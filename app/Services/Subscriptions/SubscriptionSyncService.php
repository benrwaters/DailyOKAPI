<?php

namespace App\Services\Subscriptions;

use App\Models\CarerSubscription;
use App\Models\CarerSubscriptionEvent;

class SubscriptionSyncService
{
    public function sync($carer, array $payload): array
    {
        $apple = app(AppleSubscriptionService::class)->validate($payload);

        if (!in_array($apple['product_id'], config('subscriptions.apple.allowed_products'))) {
            throw new \Exception('invalid_product');
        }

        $subscription = CarerSubscription::updateOrCreate(
            [
                'platform' => 'ios',
                'original_transaction_id' => $apple['original_transaction_id'],
            ],
            [
                'carer_id' => $carer->id,
                'product_id' => $apple['product_id'],
                'bundle_id' => $apple['bundle_id'],
                'environment' => $apple['environment'],
                'latest_transaction_id' => $apple['transaction_id'],
                'status' => $apple['status'],
                'expires_at' => $apple['expires_at'],
                'last_synced_at' => now(),
                'last_source' => 'sync',
            ]
        );

        CarerSubscriptionEvent::create([
            'carer_subscription_id' => $subscription->id,
            'carer_id' => $carer->id,
            'platform' => 'ios',
            'source' => 'sync',
            'event_type' => 'sync',
            'transaction_id' => $apple['transaction_id'],
            'original_transaction_id' => $apple['original_transaction_id'],
            'status_after' => $apple['status'],
            'expires_at_after' => $apple['expires_at'],
            'raw_payload_json' => json_encode($payload),
            'processed_at' => now(),
        ]);

        return [
            'status' => $apple['status'],
            'expires_at' => $apple['expires_at'],
        ];
    }
}