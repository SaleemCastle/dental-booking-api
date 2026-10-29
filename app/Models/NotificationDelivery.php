<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['address', 'recipient_hash', 'operation_key'];

    protected $casts = ['address' => 'encrypted', 'available_at' => 'immutable_datetime', 'attempted_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime'];
}
