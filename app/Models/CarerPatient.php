<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CarerPatient extends Model
{
    use HasFactory;

    protected $fillable = [
        'carer_id',
        'patient_id',
        'is_primary',
    ];
}
