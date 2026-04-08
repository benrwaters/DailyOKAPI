<?php

namespace App\Services\Subscriptions;

use Illuminate\Support\Facades\Http;

class AppleSubscriptionService
{
    public function validate(array $payload): array
    {
        // fallback: receipt validation
        if (!empty($payload['app_store_receipt_base64'])) {
            return $this->validate_receipt($payload['app_store_receipt_base64']);
        }

        throw new \Exception('no_receipt_provided');
    }

    private function validate_receipt(string $receipt): array
    {
        $response = Http::post(config('subscriptions.apple.verify_receipt_url'), [
            'receipt-data' => $receipt,
            'password' => config('subscriptions.apple.shared_secret'),
        ]);

        $data = $response->json();

        // sandbox fallback
        if (($data['status'] ?? null) === 21007) {
            $response = Http::post(config('subscriptions.apple.verify_receipt_url_sandbox'), [
                'receipt-data' => $receipt,
                'password' => config('subscriptions.apple.shared_secret'),
            ]);

            $data = $response->json();
        }

        if (($data['status'] ?? 999) !== 0) {
            throw new \Exception('apple_validation_failed');
        }

        $latest = collect($data['latest_receipt_info'] ?? [])
            ->sortByDesc('expires_date_ms')
            ->first();

        if (!$latest) {
            throw new \Exception('no_subscription_found');
        }

        return [
            'product_id' => $latest['product_id'],
            'original_transaction_id' => $latest['original_transaction_id'],
            'transaction_id' => $latest['transaction_id'],
            'expires_at' => date('Y-m-d H:i:s', $latest['expires_date_ms'] / 1000),
            'status' => ((int)$latest['expires_date_ms'] > now()->timestamp * 1000) ? 'active' : 'expired',
            'environment' => $data['environment'] ?? 'production',
            'bundle_id' => config('subscriptions.apple.bundle_id'),
        ];
    }
}