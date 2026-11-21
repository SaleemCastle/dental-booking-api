<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceClaimAppealResponse extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['insurer_response_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime', 'approved_amount' => 'decimal:2'];

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new \LogicException('Appeal responses are append-only.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new \LogicException('Appeal responses are append-only.');
    }
}
