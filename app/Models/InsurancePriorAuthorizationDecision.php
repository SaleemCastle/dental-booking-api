<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsurancePriorAuthorizationDecision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['insurer_reference_ciphertext'];

    protected $casts = ['authorized_amount' => 'decimal:2', 'valid_from' => 'immutable_date', 'valid_through' => 'immutable_date', 'decision_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
}
