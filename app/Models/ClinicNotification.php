<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ClinicNotification extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['variables'];

    protected $casts = ['source_revision' => 'integer', 'patient_id' => 'integer', 'appointment_id' => 'integer', 'task_id' => 'integer', 'variables' => 'encrypted:array', 'user_triggered' => 'boolean'];

    public function deliveries()
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }
}
