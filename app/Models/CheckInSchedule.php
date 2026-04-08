<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckInSchedule extends Model
{
    protected $casts = [
        'next_due_at' => 'datetime',
        'last_check_in_at' => 'datetime',
    ];


    use HasFactory;
}
