<?php

namespace App\Services\Push;

use App\Models\Carer;
use App\Models\CheckIn;
use App\Models\CheckInSchedule;
use App\Models\Device;
use App\Models\Patient;
use App\Models\PushNotificationEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PushNotificationService
{
    public function __construct(
        private readonly ApnsPushService $apns,
    ) {
    }

    public function sendPatientReminder(
        Patient $patient,
        CheckInSchedule $schedule,
        string $stage,
        string $localDate,
        string $title,
        string $body
    ): void {
        $eventKey = "patient:{$patient->id}:{$localDate}:{$stage}";

        if (PushNotificationEvent::query()->where('event_key', $eventKey)->exists()) {
            return;
        }

        $devices = Device::query()
            ->where('owner_type', 'patient')
            ->where('owner_id', $patient->id)
            ->where('platform', 'ios')
            ->where('notifications_enabled', true)
            ->get();

        $payload = [
            'type' => 'patient_check_in_reminder',
            'stage' => $stage,
            'patient_id' => $patient->id,
            'local_date' => $localDate,
            'schedule_id' => $schedule->id,
        ];

        $this->deliver($eventKey, 'patient', $patient->id, $patient->id, null, $devices, $title, $body, $payload);
    }

    public function notifyCarersOfCheckIn(Patient $patient, CheckIn $checkIn): void
    {
        $carers = Carer::query()
            ->join('carer_patients', 'carer_patients.carer_id', '=', 'carers.id')
            ->where('carer_patients.patient_id', $patient->id)
            ->select('carers.*')
            ->get();

        foreach ($carers as $carer) {
            $eventKey = "carer:{$carer->id}:checkin:{$checkIn->id}";
            $devices = Device::query()
                ->where('owner_type', 'carer')
                ->where('owner_id', $carer->id)
                ->where('platform', 'ios')
                ->where('notifications_enabled', true)
                ->get();

            $patientName = $patient->display_name ?: 'Your loved one';
            $title = 'Check-in received';
            $body = "{$patientName} has checked in.";
            $payload = [
                'type' => 'carer_check_in_received',
                'patient_id' => $patient->id,
                'check_in_id' => $checkIn->id,
                'checked_in_at' => optional($checkIn->checked_in_at)->toIso8601String(),
            ];

            $this->deliver($eventKey, 'carer', $carer->id, $patient->id, $carer->id, $devices, $title, $body, $payload);
        }
    }

    private function deliver(
        string $eventKey,
        string $ownerType,
        int $ownerId,
        ?int $patientId,
        ?int $carerId,
        $devices,
        string $title,
        string $body,
        array $payload
    ): void {
        if (PushNotificationEvent::query()->where('event_key', $eventKey)->exists()) {
            return;
        }

        if ($devices->isEmpty()) {
            PushNotificationEvent::create([
                'event_key' => $eventKey,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'patient_id' => $patientId,
                'carer_id' => $carerId,
                'category' => $payload['type'] ?? 'push',
                'title' => $title,
                'body' => $body,
                'payload_json' => $payload,
                'status' => 'skipped',
                'failure_reason' => 'no_registered_devices',
            ]);
            return;
        }

        $sent = false;
        $failureReasons = [];

        foreach ($devices as $device) {
            $result = $this->apns->send($device->push_token, $title, $body, $payload);

            if ($result['ok']) {
                $sent = true;
            } else {
                $failureReasons[] = $result['reason'] ?? 'unknown_error';
            }

            PushNotificationEvent::create([
                'event_key' => $eventKey . ':device:' . $device->id,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'patient_id' => $patientId,
                'carer_id' => $carerId,
                'device_id' => $device->id,
                'category' => $payload['type'] ?? 'push',
                'title' => $title,
                'body' => $body,
                'payload_json' => $payload,
                'status' => $result['ok'] ? 'sent' : 'failed',
                'failure_reason' => $this->truncateFailureReason($result['reason'] ?? null),
                'sent_at' => $result['ok'] ? now() : null,
            ]);
        }

        PushNotificationEvent::create([
            'event_key' => $eventKey,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'patient_id' => $patientId,
            'carer_id' => $carerId,
            'category' => $payload['type'] ?? 'push',
            'title' => $title,
            'body' => $body,
            'payload_json' => $payload,
            'status' => $sent ? 'sent' : 'failed',
            'failure_reason' => $sent
                ? null
                : $this->truncateFailureReason(implode(', ', array_unique($failureReasons))),
            'sent_at' => $sent ? now() : null,
        ]);

        if (!$sent) {
            Log::info('push delivery did not send to any device', [
                'event_key' => $eventKey,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'failure_reasons' => $failureReasons,
            ]);
        }
    }

    private function truncateFailureReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return Str::limit($reason, 250, '...');
    }
}
