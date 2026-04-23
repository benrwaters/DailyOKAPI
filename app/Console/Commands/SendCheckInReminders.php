<?php

namespace App\Console\Commands;

use App\Models\CheckIn;
use App\Models\CheckInSchedule;
use App\Services\Push\PushNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendCheckInReminders extends Command
{
    protected $signature = 'notifications:send-check-in-reminders';

    protected $description = 'Send due and overdue daily check-in push reminders.';

    public function handle(PushNotificationService $pushes): int
    {
        $nowUtc = CarbonImmutable::now('UTC');
        $windowMinutes = (int) config('push.reminder_window_minutes', 10);
        $processed = 0;

        CheckInSchedule::query()
            ->where('status', 'active')
            ->with('patient')
            ->chunkById(100, function ($schedules) use ($pushes, $nowUtc, $windowMinutes, &$processed) {
                foreach ($schedules as $schedule) {
                    if (!$schedule->patient) {
                        continue;
                    }

                    $timezone = $schedule->timezone ?: 'Europe/London';
                    $localNow = $nowUtc->setTimezone($timezone);
                    $localDate = $localNow->toDateString();
                    $slots = $schedule->configuredSlots();
                    $checkedSlotKeys = $this->checkedSlotKeysForLocalDay($schedule->patient_id, $timezone, $nowUtc);
                    $pendingSlots = [];

                    foreach ($slots as $slot) {
                        if (in_array($slot['key'], $checkedSlotKeys, true)) {
                            continue;
                        }

                        [$hour, $minute] = array_pad(explode(':', $slot['time_local'], 2), 2, '00');
                        $dueLocal = $localNow->startOfDay()->setTime((int) $hour, (int) $minute, 0);
                        $pendingSlots[] = [
                            'key' => $slot['key'],
                            'time_local' => $slot['time_local'],
                            'due_local' => $dueLocal,
                            'due_utc' => $dueLocal->setTimezone('UTC'),
                        ];
                    }

                    usort($pendingSlots, function (array $a, array $b) {
                        return $a['due_utc']->getTimestamp() <=> $b['due_utc']->getTimestamp();
                    });

                    foreach ($pendingSlots as $slot) {
                        $stages = [];

                        $minutesBefore = (int) $schedule->reminder_minutes_before;
                        if ($minutesBefore > 0) {
                            $stages[] = [
                                'stage' => 'reminder',
                                'target' => $slot['due_local']->subMinutes($minutesBefore),
                                'audience' => 'patient',
                                'title' => 'DailyOK reminder',
                                'body' => 'Your check-in is coming up soon.',
                            ];
                        }

                        $stages[] = [
                            'stage' => 'due',
                            'target' => $slot['due_local'],
                            'audience' => 'patient',
                            'title' => 'Time to check in',
                            'body' => 'Please check in now so your carer knows you are OK.',
                        ];

                        $stages[] = [
                            'stage' => 'late_30',
                            'target' => $slot['due_local']->addMinutes(30),
                            'audience' => 'patient',
                            'title' => 'Check-in overdue',
                            'body' => 'You have missed your check-in. Please confirm you are OK.',
                        ];

                        $stages[] = [
                            'stage' => 'late_60_carer',
                            'target' => $slot['due_local']->addHour(),
                            'audience' => 'carer',
                            'title' => 'Check-in overdue',
                            'body' => 'Loved one has not checked in yet.',
                        ];

                        foreach ($stages as $stage) {
                            $targetUtc = $stage['target']->setTimezone('UTC');
                            $shouldSend = match ($stage['stage']) {
                                'late_30', 'late_60_carer' => $nowUtc->gte($targetUtc),
                                default => $nowUtc->gte($targetUtc) && $nowUtc->lte($targetUtc->addMinutes($windowMinutes)),
                            };

                            if (!$shouldSend) {
                                continue;
                            }

                            if (($stage['audience'] ?? 'patient') === 'patient') {
                                $pushes->sendPatientReminder(
                                    $schedule->patient,
                                    $schedule,
                                    $slot['key'],
                                    $stage['stage'],
                                    $localDate,
                                    $stage['title'],
                                    $stage['body']
                                );
                            } else {
                                $pushes->notifyCarersOfMissedCheckIn(
                                    $schedule->patient,
                                    $schedule,
                                    $localDate,
                                    $slot['key']
                                );
                            }

                            $processed++;
                        }
                    }

                    $nextDueAt = $this->nextDueAtForSchedule($schedule, $nowUtc, $pendingSlots);
                    if ($schedule->next_due_at === null || !$schedule->next_due_at->equalTo($nextDueAt->toMutable())) {
                        $schedule->next_due_at = $nextDueAt->toMutable();
                        $schedule->save();
                    }
                }
            });

        $this->info("Processed {$processed} reminder windows.");

        return self::SUCCESS;
    }

    private function checkedSlotKeysForLocalDay(int $patientId, string $timezone, CarbonImmutable $nowUtc): array
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

    private function localDayUtcBounds(string $timezone, CarbonImmutable $nowUtc): array
    {
        $localNow = $nowUtc->setTimezone($timezone);

        return [
            $localNow->startOfDay()->setTimezone('UTC'),
            $localNow->endOfDay()->setTimezone('UTC'),
        ];
    }

    private function nextDueAtForSchedule(CheckInSchedule $schedule, CarbonImmutable $nowUtc, array $pendingSlots): CarbonImmutable
    {
        foreach ($pendingSlots as $slot) {
            if ($nowUtc->gte($slot['due_utc'])) {
                return $nowUtc;
            }
        }

        if (!empty($pendingSlots)) {
            return $pendingSlots[0]['due_utc'];
        }

        $timezone = $schedule->timezone ?: 'Europe/London';
        $localTomorrow = $nowUtc->setTimezone($timezone)->addDay();
        $firstSlot = $schedule->configuredSlots()[0] ?? ['time_local' => $schedule->check_in_time_local];
        [$hour, $minute] = array_pad(explode(':', $firstSlot['time_local'], 2), 2, '00');

        return $localTomorrow
            ->startOfDay()
            ->setTime((int) $hour, (int) $minute, 0)
            ->setTimezone('UTC');
    }
}
