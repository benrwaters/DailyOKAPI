<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $table = 'otp_codes';

    protected $fillable = [
        'phone_e164',
        'code_hash',
        'expires_at',
        'used_at',
        'purpose',
        'attempt_count',
        'locked_until',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'locked_until' => 'datetime',
    ];
}
