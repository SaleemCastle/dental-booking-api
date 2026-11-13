<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MessagingUserPermission extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['internal_send' => 'boolean', 'internal_receive' => 'boolean', 'patient_send' => 'boolean',
        'internal_view' => 'boolean', 'patient_view' => 'boolean', 'settings_manage' => 'boolean', 'history_view' => 'boolean',
        'assign' => 'boolean', 'escalate' => 'boolean', 'supervise' => 'boolean', 'archive' => 'boolean', 'redact' => 'boolean', 'export' => 'boolean'];
}
