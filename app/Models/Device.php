<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'platform',
        'push_token',
        'device_model',
        'os_version',
        'app_version',
        'notifications_enabled',
        'last_registered_at',
        'last_seen_at',
    ];

    protected $casts = [
        'notifications_enabled' => 'boolean',
        'last_registered_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
