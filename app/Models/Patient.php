<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Patient extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'display_name',
        'phone_e164',
        'verification_hint',
        'verification_hash',
        'status',
    ];

    protected $hidden = [
        'verification_hash',
    ];
}
