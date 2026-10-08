<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dentist extends Model
{
    use HasFactory;

    protected $table = 'dentist';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'contact_number',
        'specialization',
    ];

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class);
    }

    public function clinicalNotes()
    {
        return $this->hasMany(ClinicalNote::class);
    }
}
