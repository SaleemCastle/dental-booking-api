<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ClinicNotification extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['variables'];

    protected $casts = ['variables' => 'encrypted:array', 'user_triggered' => 'boolean'];

    public function deliveries()
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }
}
