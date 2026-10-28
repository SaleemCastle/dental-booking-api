<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PaymentProviderConfiguration extends Model
{
    use BelongsToTenant;

    protected $fillable = ['provider', 'credentials', 'success_url', 'cancel_url', 'enabled'];

    protected $hidden = ['credentials'];

    protected $casts = ['credentials' => 'encrypted:array', 'enabled' => 'boolean'];
}
