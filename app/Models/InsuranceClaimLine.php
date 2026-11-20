<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceClaimLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['quantity' => 'integer', 'service_date' => 'date', 'unit_amount' => 'decimal:2', 'gross_amount' => 'decimal:2'];
}
