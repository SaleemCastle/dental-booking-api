<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use BelongsToTenant, HasFactory;

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    public const OPEN_STATUSES = ['pending', 'in_progress'];

    protected $fillable = [
        'patient_id',
        'appointment_id',
        'assignee_id',
        'due_date',
        'priority',
        'status',
        'description',
        'patient_reminder_at',
    ];

    protected $casts = ['communication_revision' => 'integer',
        'patient_id' => 'integer',
        'appointment_id' => 'integer',
        'assignee_id' => 'integer',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'patient_reminder_at' => 'datetime',
    ];

    protected function dueDate(): Attribute
    {
        return Attribute::make(set: fn ($value) => $this->asDateTime($value)->toDateString());
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
