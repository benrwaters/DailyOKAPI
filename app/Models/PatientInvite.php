<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatientInvite extends Model
{
    use HasFactory;

    protected $casts = [
    'is_active' => 'boolean',
    'issued_at' => 'datetime',
    'last_sent_at' => 'datetime',
    'expires_at' => 'datetime',
    'first_used_at' => 'datetime',
    'last_used_at' => 'datetime',
];



    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
