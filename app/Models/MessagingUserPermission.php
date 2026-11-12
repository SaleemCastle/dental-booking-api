<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MessagingUserPermission extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['internal_send' => 'boolean', 'internal_receive' => 'boolean', 'patient_send' => 'boolean'];
}
