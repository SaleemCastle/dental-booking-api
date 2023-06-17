<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    use HasFactory;

    protected $table = 'patient';

    protected $fillable = [
        'firstName',
        'lastName',
        'appointments',
        'sex',
        'streetAddress',
        'town',
        'city',
        'notes'
    ];
}
