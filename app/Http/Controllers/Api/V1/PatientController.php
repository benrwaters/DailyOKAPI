<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\CheckInSchedule;
use App\Models\Patient;
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

        $now_utc = now();

        if (!$schedule) {
            return response()->json([
                'ok' => true,
                'server_now_at' => $now_utc->toIso8601String(),
                'schedule' => null,
                'last_check_in_at' => null,
            ]);
        }

        $next_due_at = $this->compute_next_due_at(
            $schedule->timezone,
            $schedule->check_in_time_local,
            $schedule->last_check_in_at,
            $now_utc
        );

        if (
            $schedule->next_due_at === null
            || !$schedule->next_due_at->equalTo($next_due_at)
        ) {
            $schedule->next_due_at = $next_due_at;
            $schedule->save();
        }

        return response()->json([
            'ok' => true,
            'server_now_at' => $now_utc->toIso8601String(),
            'schedule' => [
                'next_due_at' => $next_due_at->toIso8601String(),
                'check_in_time_local' => $schedule->check_in_time_local,
                'timezone' => $schedule->timezone,
            ],
            'last_check_in_at' => $schedule->last_check_in_at
                ? $schedule->last_check_in_at->toIso8601String()
                : null,
        ]);
    }

    public function check_in_now(Request $request)
    {
        /** @var Patient $patient */
        $patient = $request->user();

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:normal'],
        ]);

        $schedule = CheckInSchedule::query()
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        $now_utc = now();

        if (
            $schedule->last_check_in_at !== null
            && $this->is_same_local_day($schedule->timezone, $schedule->last_check_in_at, $now_utc)
        ) {
            $next_due_at = $this->next_due_time_local_to_utc(
                $schedule->timezone,
                $schedule->check_in_time_local,
                $now_utc
            );

            if ($schedule->next_due_at === null || !$schedule->next_due_at->equalTo($next_due_at)) {
                $schedule->next_due_at = $next_due_at;
                $schedule->save();
            }

            return response()->json([
                'error' => 'already_checked_in',
                'message' => 'You’ve already checked in today.',
                'next_due_at' => $next_due_at->toIso8601String(),
            ], 409);
        }

        $check_in = new CheckIn();
        $check_in->patient_id = $patient->id;
        $check_in->checked_in_at = $now_utc;
        $check_in->type = $validated['type'];
        $check_in->save();

        $next_due_at = $this->next_due_time_local_to_utc(
            $schedule->timezone,
            $schedule->check_in_time_local,
            $now_utc
        );

        $schedule->last_check_in_at = $now_utc;
        $schedule->next_due_at = $next_due_at;
        $schedule->save();

        return response()->json([
            'ok' => true,
            'server_now_at' => $now_utc->toIso8601String(),
            'checked_in_at' => $now_utc->toIso8601String(),
            'next_due_at' => $next_due_at->toIso8601String(),
        ]);
    }

    private function compute_next_due_at(
        string $timezone,
        string $check_in_time_local,
        ?Carbon $last_check_in_at,
        Carbon $now_utc
    ): Carbon {
        if (
            $last_check_in_at !== null
            && $this->is_same_local_day($timezone, $last_check_in_at, $now_utc)
        ) {
            return $this->next_due_time_local_to_utc(
                $timezone,
                $check_in_time_local,
                $now_utc
            );
        }

        $local_now = $now_utc->copy()->timezone($timezone);

        [$hour, $minute] = array_pad(explode(':', $check_in_time_local, 2), 2, '00');

        $today_due_local = $local_now->copy()->setTime((int) $hour, (int) $minute, 0);

        if ($local_now->lt($today_due_local)) {
            return $today_due_local->copy()->timezone('UTC');
        }

        return $now_utc;
    }

    private function next_due_time_local_to_utc(
        string $timezone,
        string $check_in_time_local,
        Carbon $now_utc
    ): Carbon {
        $local_now = $now_utc->copy()->timezone($timezone);

        [$hour, $minute] = array_pad(explode(':', $check_in_time_local, 2), 2, '00');

        $next_due_local = $local_now->copy()
            ->startOfDay()
            ->addDay()
            ->setTime((int) $hour, (int) $minute, 0);

        return $next_due_local->timezone('UTC');
    }

    private function is_same_local_day(string $timezone, Carbon $a_utc, Carbon $b_utc): bool
    {
        return $a_utc->copy()->timezone($timezone)->toDateString()
            === $b_utc->copy()->timezone($timezone)->toDateString();
    }
}