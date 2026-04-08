<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarerSubscriptionEvent extends Model
{
    protected $fillable = [
        'carer_subscription_id',
        'carer_id',
        'platform',
        'source',
        'event_type',
        'event_subtype',
        'notification_uuid',
        'transaction_id',
        'original_transaction_id',
        'status_after',
        'expires_at_after',
        'raw_payload_json',
        'processed_at',
    ];
}