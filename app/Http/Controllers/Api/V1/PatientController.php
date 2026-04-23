<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CarerPatient;
use App\Models\CheckIn;
use App\Models\CheckInSchedule;
use App\Models\Patient;
use App\Services\Push\PushNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PatientController extends Controller
{
    public function home(Request $request)
    {
        /** @var Patient $patient */
        $patient = $request->user();

        $schedule = CheckInSchedule::query()
            ->where('patient_id', $patient->id)
            ->first();

        $nowUtc = now();

        if (!$schedule) {
            return response()->json([
                'ok' => true,
                'server_now_at' => $nowUtc->toIso8601String(),
                'schedule' => null,
                'last_check_in_at' => null,
            ]);
        }

        $state = $this->scheduleState($schedule, $patient, $nowUtc);

        if (
            $schedule->next_due_at === null
            || !$schedule->next_due_at->equalTo($state['next_due_at'])
        ) {
            $schedule->next_due_at = $state['next_due_at'];
            $schedule->save();
        }

        return response()->json([
            'ok' => true,
            'server_now_at' => $nowUtc->toIso8601String(),
            'schedule' => [
                'next_due_at' => $state['next_due_at']->toIso8601String(),
                'check_in_time_local' => $state['display_time_local'],
                'second_check_in_time_local' => $schedule->second_check_in_time_local,
                'timezone' => $schedule->timezone,
                'manual_check_in_requested_at' => $this->active_manual_check_in_requested_at($schedule, $nowUtc)?->toIso8601String(),
            ],
            'last_check_in_at' => $schedule->last_check_in_at
                ? $schedule->last_check_in_at->toIso8601String()
                : null,
        ]);
    }

    public function check_in_now(Request $request, PushNotificationService $pushes)
    {
        /** @var Patient $patient */
        $patient = $request->user();

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:normal'],
        ]);

        $schedule = CheckInSchedule::query()
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        $nowUtc = now();
        $manualRequestAt = $this->active_manual_check_in_requested_at($schedule, $nowUtc);
        $state = $this->scheduleState($schedule, $patient, $nowUtc);
        $pendingSlot = $state['pending_slot'];

        if ($pendingSlot !== null && $nowUtc->lt($pendingSlot['due_at_utc']) && $manualRequestAt === null) {
            return response()->json([
                'error' => 'not_due_yet',
                'message' => 'Your next check-in is not due yet.',
                'next_due_at' => $pendingSlot['due_at_utc']->toIso8601String(),
            ], 409);
        }

        if ($pendingSlot === null && $manualRequestAt === null) {
            if ($schedule->next_due_at === null || !$schedule->next_due_at->equalTo($state['next_due_at'])) {
                $schedule->next_due_at = $state['next_due_at'];
                $schedule->save();
            }

            return response()->json([
                'error' => 'already_checked_in',
                'message' => 'You’ve already completed today’s check-ins.',
                'next_due_at' => $state['next_due_at']->toIso8601String(),
            ], 409);
        }

        $slotKey = $pendingSlot['key'] ?? 'manual_extra';

        $checkIn = new CheckIn();
        $checkIn->patient_id = $patient->id;
        $checkIn->checked_in_at = $nowUtc;
        $checkIn->type = $validated['type'];
        $checkIn->slot_key = $slotKey;
        $checkIn->save();

        $nextState = $this->scheduleState($schedule->fresh(), $patient, $nowUtc);

        $schedule->last_check_in_at = $nowUtc;
        $schedule->next_due_at = $nextState['next_due_at'];
        if ($manualRequestAt !== null) {
            $schedule->manual_check_in_consumed_at = $nowUtc;
        }
        $schedule->save();

        $pushes->notifyCarersOfCheckIn($patient, $checkIn);

        $carerCount = CarerPatient::query()
            ->where('patient_id', $patient->id)
            ->count();

        return response()->json([
            'ok' => true,
            'server_now_at' => $nowUtc->toIso8601String(),
            'checked_in_at' => $nowUtc->toIso8601String(),
            'next_due_at' => $nextState['next_due_at']->toIso8601String(),
            'carer_notifications_attempted' => $carerCount,
        ]);
    }

    private function scheduleState(CheckInSchedule $schedule, Patient $patient, Carbon $nowUtc): array
    {
        $timezone = $schedule->timezone ?: 'Europe/London';
        $localNow = $nowUtc->copy()->timezone($timezone);
        $slots = $schedule->configuredSlots();
        $checkedSlotKeys = $this->checkedSlotKeysForLocalDay($patient->id, $timezone, $nowUtc);

        $pendingSlots = [];
        foreach ($slots as $slot) {
            if (in_array($slot['key'], $checkedSlotKeys, true)) {
                continue;
            }

            $dueLocal = $this->localDateTimeForTimeString($localNow, $slot['time_local']);
            $pendingSlots[] = [
                'key' => $slot['key'],
                'time_local' => $slot['time_local'],
                'due_at_utc' => $dueLocal->copy()->timezone('UTC'),
            ];
        }

        usort($pendingSlots, function (array $a, array $b) {
            return $a['due_at_utc']->getTimestamp() <=> $b['due_at_utc']->getTimestamp();
        });

        foreach ($pendingSlots as $slot) {
            if ($nowUtc->gte($slot['due_at_utc'])) {
                return [
                    'next_due_at' => $nowUtc,
                    'pending_slot' => $slot,
                    'display_time_local' => $slot['time_local'],
                ];
            }
        }

        if (!empty($pendingSlots)) {
            return [
                'next_due_at' => $pendingSlots[0]['due_at_utc'],
                'pending_slot' => $pendingSlots[0],
                'display_time_local' => $pendingSlots[0]['time_local'],
            ];
        }

        $firstSlot = $slots[0] ?? ['time_local' => $schedule->check_in_time_local];
        $tomorrowLocal = $localNow->copy()->addDay();
        $nextDueAt = $this->localDateTimeForTimeString($tomorrowLocal, $firstSlot['time_local'])->timezone('UTC');

        return [
            'next_due_at' => $nextDueAt,
            'pending_slot' => null,
            'display_time_local' => $firstSlot['time_local'],
        ];
    }

    private function checkedSlotKeysForLocalDay(int $patientId, string $timezone, Carbon $nowUtc): array
    {
        [$dayStartUtc, $dayEndUtc] = $this->localDayUtcBounds($timezone, $nowUtc);

        $checkIns = CheckIn::query()
            ->where('patient_id', $patientId)
            ->whereBetween('checked_in_at', [$dayStartUtc, $dayEndUtc])
            ->orderBy('checked_in_at')
            ->get(['slot_key']);

        $slotKeys = [];
        $legacyKeys = ['primary', 'secondary'];

        foreach ($checkIns as $checkIn) {
            if (!empty($checkIn->slot_key)) {
                $slotKeys[] = $checkIn->slot_key;
                continue;
            }

            if (!empty($legacyKeys)) {
                $slotKeys[] = array_shift($legacyKeys);
            }
        }

        return array_values(array_unique($slotKeys));
    }

    private function localDayUtcBounds(string $timezone, Carbon $nowUtc): array
    {
        $localNow = $nowUtc->copy()->timezone($timezone);
        $dayStartUtc = $localNow->copy()->startOfDay()->timezone('UTC');
        $dayEndUtc = $localNow->copy()->endOfDay()->timezone('UTC');

        return [$dayStartUtc, $dayEndUtc];
    }

    private function localDateTimeForTimeString(Carbon $localDate, string $timeLocal): Carbon
    {
        [$hour, $minute] = array_pad(explode(':', $timeLocal, 2), 2, '00');

        return $localDate->copy()->startOfDay()->setTime((int) $hour, (int) $minute, 0);
    }

    private function is_same_local_day(string $timezone, Carbon $aUtc, Carbon $bUtc): bool
    {
        return $aUtc->copy()->timezone($timezone)->toDateString()
            === $bUtc->copy()->timezone($timezone)->toDateString();
    }

    private function active_manual_check_in_requested_at(CheckInSchedule $schedule, Carbon $nowUtc): ?Carbon
    {
        if ($schedule->manual_check_in_requested_at === null) {
            return null;
        }

        $localDate = $nowUtc->copy()->timezone($schedule->timezone)->toDateString();
        if ($schedule->manual_check_in_request_local_date !== $localDate) {
            return null;
        }

        if (
            $schedule->manual_check_in_consumed_at !== null
            && $this->is_same_local_day($schedule->timezone, $schedule->manual_check_in_consumed_at, $nowUtc)
        ) {
            return null;
        }

        return $schedule->manual_check_in_requested_at;
    }
}
