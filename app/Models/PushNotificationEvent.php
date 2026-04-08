<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PushNotificationEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_key',
        'owner_type',
        'owner_id',
        'patient_id',
        'carer_id',
        'device_id',
        'category',
        'title',
        'body',
        'payload_json',
        'status',
        'failure_reason',
        'sent_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'sent_at' => 'datetime',
    ];
}
