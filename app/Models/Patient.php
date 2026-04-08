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

    public function devices()
    {
        return $this->hasMany(Device::class, 'owner_id')
            ->where('owner_type', 'patient');
    }

    public function schedule()
    {
        return $this->hasOne(CheckInSchedule::class);
    }

    public function checkIns()
    {
        return $this->hasMany(CheckIn::class);
    }

    public function invites()
    {
        return $this->hasMany(PatientInvite::class);
    }

    public function carerLinks()
    {
        return $this->hasMany(CarerPatient::class);
    }
}
