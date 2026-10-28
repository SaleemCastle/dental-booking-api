<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PaymentLink extends Model
{
    use BelongsToTenant;

    /** Unresolved external checkouts must block another checkout or balance changes. */
    public const OUTSTANDING = ['creating', 'active', 'pending', 'unknown'];

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['operation_key', 'payment_provider_configuration_id'];

    protected $casts = ['amount' => 'decimal:2', 'expires_at' => 'immutable_datetime'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function configuration()
    {
        return $this->belongsTo(PaymentProviderConfiguration::class, 'payment_provider_configuration_id');
    }
}
