<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

class Carer extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'full_name',
        'email',
        'phone_e164',
        'phone_verified_at',
        'email_verified_at',
        'status',
    ];

    protected $casts = [
        'phone_verified_at' => 'datetime',
        'email_verified_at' => 'datetime',
    ];


    public function subscriptions()
    {
        return $this->hasMany(\App\Models\CarerSubscription::class);
    }

    public function devices()
    {
        return $this->hasMany(\App\Models\Device::class, 'owner_id')
            ->where('owner_type', 'carer');
    }

    public function patientLinks()
    {
        return $this->hasMany(\App\Models\CarerPatient::class);
    }
}
