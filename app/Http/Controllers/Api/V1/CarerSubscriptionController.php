<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncCarerSubscriptionRequest;
use App\Services\Subscriptions\SubscriptionSyncService;
use Illuminate\Http\Request;

class CarerSubscriptionController extends Controller
{
    public function sync(SyncCarerSubscriptionRequest $request)
    {
        $carer = $request->user();

        $result = app(SubscriptionSyncService::class)->sync(
            $carer,
            $request->validated()
        );

        return response()->json([
            'ok' => true,
            'status' => $result['status'],
            'expires_at' => $result['expires_at'],
        ]);
    }
}