<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PaymentProviderEvent extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];
}
