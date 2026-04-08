<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckInSchedule extends Model
{
    protected $fillable = [
        'patient_id',
        'cadence',
        'timezone',
        'check_in_time_local',
        'grace_minutes',
        'reminder_minutes_before',
        'next_due_at',
        'last_check_in_at',
        'status',
    ];

    protected $casts = [
        'next_due_at' => 'datetime',
        'last_check_in_at' => 'datetime',
    ];


    use HasFactory;

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
