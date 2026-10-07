<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    use HasFactory;

    protected $table = 'referral';

    protected $fillable = [
        'patient_id',
        'dentist_id',
        'referred_to_email',
        'referral_date',
        'referral_notes',
    ];

    protected $casts = [
        'referral_date' => 'date',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function dentist()
    {
        return $this->belongsTo(Dentist::class);
    }
}
