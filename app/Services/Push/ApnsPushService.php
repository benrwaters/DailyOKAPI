<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ApnsPushService
{
    public function configured(): bool
    {
        return filled(config('push.apns.key_id'))
            && filled(config('push.apns.team_id'))
            && filled(config('push.apns.bundle_id'))
            && filled($this->privateKey());
    }

    public function send(string $deviceToken, string $title, string $body, array $payload = []): array
    {
        if (!$this->configured()) {
            return [
                'ok' => false,
                'status' => null,
                'reason' => 'apns_not_configured',
            ];
        }

        $deviceToken = $this->normalizeDeviceToken($deviceToken);
        if ($deviceToken === '') {
            return [
                'ok' => false,
                'status' => null,
                'reason' => 'invalid_device_token',
            ];
        }

        try {
            $jwt = $this->createJwt();
            $url = rtrim($this->baseUrl(), '/') . '/3/device/' . $deviceToken;

            $response = Http::withToken($jwt)
                ->timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'apns-topic' => config('push.apns.bundle_id'),
                    'apns-push-type' => 'alert',
                    'apns-priority' => '10',
                ])
                ->withBody(json_encode([
                    'aps' => [
                        'alert' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'sound' => 'default',
                    ],
                    'dailyok' => $payload,
                ], JSON_UNESCAPED_SLASHES), 'application/json')
                ->send('POST', $url);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'reason' => $response->successful()
                    ? null
                    : ($response->json('reason') ?? Str::limit($response->body(), 255, '')),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'reason' => Str::limit($e->getMessage(), 255, ''),
            ];
        }
    }

    private function createJwt(): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'ES256',
            'kid' => config('push.apns.key_id'),
        ], JSON_UNESCAPED_SLASHES));

        $claims = $this->base64UrlEncode(json_encode([
            'iss' => config('push.apns.team_id'),
            'iat' => time(),
        ], JSON_UNESCAPED_SLASHES));

        $input = $header . '.' . $claims;
        $signature = '';

        openssl_sign($input, $signature, $this->privateKey(), 'sha256');

        return $input . '.' . $this->base64UrlEncode($this->convertDerToJose($signature, 64));
    }

    private function baseUrl(): string
    {
        return config('push.apns.use_sandbox')
            ? 'https://api.sandbox.push.apple.com'
            : 'https://api.push.apple.com';
    }

    private function privateKey(): ?string
    {
        $inline = config('push.apns.private_key');
        if (filled($inline)) {
            return str_replace('\n', "\n", $inline);
        }

        $path = config('push.apns.private_key_path');
        if (filled($path) && is_readable($path)) {
            return file_get_contents($path) ?: null;
        }

        return null;
    }

    private function normalizeDeviceToken(string $deviceToken): string
    {
        return preg_replace('/[^A-Fa-f0-9]/', '', $deviceToken) ?? '';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function convertDerToJose(string $der, int $partLength): string
    {
        $offset = 3;
        $rLength = ord($der[$offset]);
        $offset++;
        $r = substr($der, $offset, $rLength);
        $offset += $rLength + 1;
        $sLength = ord($der[$offset]);
        $offset++;
        $s = substr($der, $offset, $sLength);

        return str_pad(ltrim($r, "\x00"), $partLength / 2, "\x00", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\x00"), $partLength / 2, "\x00", STR_PAD_LEFT);
    }
}
