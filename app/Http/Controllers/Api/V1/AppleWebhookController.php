<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppleWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        // TODO: verify Apple signature (JWS)
        // TODO: parse notification

        // store raw for now
        \App\Models\CarerSubscriptionEvent::create([
            'platform' => 'ios',
            'source' => 'apple_notification',
            'event_type' => $payload['notificationType'] ?? 'unknown',
            'notification_uuid' => $payload['notificationUUID'] ?? null,
            'raw_payload_json' => json_encode($payload),
            'processed_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}