<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckIn extends Model
{
    protected $table = 'check_ins';

    protected $fillable = [
        'patient_id',
        'checked_in_at',
        'type',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
    ];
}
