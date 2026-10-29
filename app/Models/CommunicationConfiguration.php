<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CommunicationConfiguration extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['settings'];

    protected $casts = ['settings' => 'encrypted:array', 'enabled' => 'boolean'];
}
