<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicalNote extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'patient_id',
        'dentist_id',
        'appointment_id',
        'note_text',
    ];

    protected $casts = [
        'patient_id' => 'integer',
        'dentist_id' => 'integer',
        'appointment_id' => 'integer',
        'author_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'deleted_by_user_id' => 'integer',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function dentist()
    {
        return $this->belongsTo(Dentist::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function lastEditor()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }
}
