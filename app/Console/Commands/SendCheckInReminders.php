<?php

namespace App\Console\Commands;

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

                    if (
                        $schedule->last_check_in_at !== null
                        && $schedule->last_check_in_at->copy()->timezone($timezone)->toDateString() === $localDate
                    ) {
                        continue;
                    }

                    [$hour, $minute] = array_pad(explode(':', $schedule->check_in_time_local ?: '10:00', 2), 2, '00');

                    $dueLocal = $localNow->copy()->startOfDay()->setTime((int) $hour, (int) $minute, 0);
                    $stages = [];

                    $minutesBefore = (int) $schedule->reminder_minutes_before;
                    if ($minutesBefore > 0) {
                        $stages[] = [
                            'stage' => 'reminder',
                            'target' => $dueLocal->subMinutes($minutesBefore),
                            'title' => 'DailyOK reminder',
                            'body' => 'Your check-in is coming up soon.',
                        ];
                    }

                    $stages[] = [
                        'stage' => 'due',
                        'target' => $dueLocal,
                        'title' => 'Time to check in',
                        'body' => 'Please check in now so your carer knows you are OK.',
                    ];

                    $stages[] = [
                        'stage' => 'late',
                        'target' => $dueLocal->addHour(),
                        'title' => 'Check-in overdue',
                        'body' => 'You have missed your check-in. Please confirm you are OK.',
                    ];

                    foreach ($stages as $stage) {
                        $targetUtc = $stage['target']->setTimezone('UTC');
                        $shouldSend = match ($stage['stage']) {
                            'late' => $nowUtc->gte($targetUtc),
                            default => $nowUtc->gte($targetUtc) && $nowUtc->lte($targetUtc->addMinutes($windowMinutes)),
                        };

                        if (!$shouldSend) {
                            continue;
                        }

                        $pushes->sendPatientReminder(
                            $schedule->patient,
                            $schedule,
                            $stage['stage'],
                            $localDate,
                            $stage['title'],
                            $stage['body']
                        );

                        $processed++;
                    }
                }
            });

        $this->info("Processed {$processed} reminder windows.");

        return self::SUCCESS;
    }
}
