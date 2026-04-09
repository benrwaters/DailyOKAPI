<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Carer;
use App\Models\Device;
use App\Models\Patient;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'in:ios'],
            'push_token' => ['required', 'string', 'max:512'],
            'device_model' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:255'],
            'notifications_enabled' => ['nullable', 'boolean'],
        ]);

        $normalizedPushToken = preg_replace('/[^A-Fa-f0-9]/', '', $validated['push_token']) ?? '';
        if ($normalizedPushToken === '') {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_push_token',
                'message' => 'Push token was invalid.',
            ], 422);
        }

        $owner = $request->user();
        $ownerType = match (true) {
            $owner instanceof Carer => 'carer',
            $owner instanceof Patient => 'patient',
            default => abort(403, 'Unsupported device owner.'),
        };

        Device::query()
            ->where('push_token', $normalizedPushToken)
            ->where(function ($query) use ($ownerType, $owner) {
                $query->where('owner_type', '!=', $ownerType)
                    ->orWhere('owner_id', '!=', $owner->id);
            })
            ->delete();

        $device = Device::updateOrCreate(
            ['push_token' => $normalizedPushToken],
            [
                'owner_type' => $ownerType,
                'owner_id' => $owner->id,
                'platform' => $validated['platform'],
                'device_model' => $validated['device_model'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'notifications_enabled' => $validated['notifications_enabled'] ?? true,
                'last_registered_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'ok' => true,
            'device' => [
                'id' => $device->id,
                'owner_type' => $device->owner_type,
                'owner_id' => $device->owner_id,
                'notifications_enabled' => (bool) $device->notifications_enabled,
            ],
        ]);
    }
}
