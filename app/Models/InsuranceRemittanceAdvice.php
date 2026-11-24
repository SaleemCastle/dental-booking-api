<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceRemittanceAdvice extends Model
{
    use BelongsToTenant;

    protected $table = 'insurance_remittance_advices';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['command_key', 'request_hash'];

    protected $casts = [
        'advice_date' => 'immutable_date',
        'recorded_at' => 'immutable_datetime',
        'reported_billed_total' => 'decimal:2',
        'reported_allowed_total' => 'decimal:2',
        'reported_paid_total' => 'decimal:2',
        'reported_denied_total' => 'decimal:2',
        'reported_adjusted_total' => 'decimal:2',
    ];

    public function lines()
    {
        return $this->hasMany(InsuranceRemittanceLine::class, 'advice_id')->orderBy('line_number');
    }
}
