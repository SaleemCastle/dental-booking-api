<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentProviderConfiguration extends Model
{
    use BelongsToTenant;

    protected $fillable = ['provider', 'credentials', 'success_url', 'cancel_url', 'enabled'];

    protected $hidden = ['credentials'];

    protected $casts = ['credentials' => 'encrypted:array', 'enabled' => 'boolean', 'sandbox_verified_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(fn ($configuration) => $configuration->webhook_endpoint ??= (string) Str::uuid());
    }
}
