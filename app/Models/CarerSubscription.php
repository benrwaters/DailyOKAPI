<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarerSubscription extends Model
{
    protected $fillable = [
        'carer_id',
        'platform',
        'provider',
        'product_id',
        'bundle_id',
        'environment',
        'original_transaction_id',
        'latest_transaction_id',
        'status',
        'purchase_date',
        'expires_at',
        'revoked_at',
        'grace_period_expires_at',
        'last_synced_at',
        'last_notification_at',
        'last_source',
    ];
}