<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceRemittanceLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'billed_amount' => 'decimal:2',
        'allowed_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'denied_amount' => 'decimal:2',
        'adjusted_amount' => 'decimal:2',
    ];
}
