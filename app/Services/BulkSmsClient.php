<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BulkSmsClient
{
    public function send_sms(string $to_e164, string $body, ?string $from = null): array
    {
        $enabled = config('bulksms.enabled');

        if (!$enabled) {
            Log::info('bulksms disabled - skipping send', [
                'to' => $to_e164,
                'body' => $body,
            ]);

            return [
                'ok' => true,
                'skipped' => true,
                'provider' => 'bulksms',
            ];
        }

        $username = config('bulksms.username');
        $password = config('bulksms.password');

        $payload = [
            'to' => $to_e164,
            'body' => $body,
        ];

        $from_value = $from ?? config('bulksms.from');
        if (!empty($from_value)) {
            $payload['from'] = $from_value;
        }

        // BulkSMS JSON API: POST /v1/messages with JSON body like { "to": "...", "body": "..." }
        // Base URL: https://api.bulksms.com/v1 :contentReference[oaicite:3]{index=3}
        $response = Http::withBasicAuth($username, $password)
            ->acceptJson()
            ->asJson()
            ->post(rtrim(config('bulksms.base_url'), '/') . '/messages', $payload);

        if ($response->successful()) {
            return [
                'ok' => true,
                'provider' => 'bulksms',
                'status' => $response->status(),
                'data' => $response->json(),
            ];
        }

        return [
            'ok' => false,
            'provider' => 'bulksms',
            'status' => $response->status(),
            'error' => $response->json() ?: $response->body(),
        ];
    }
}
