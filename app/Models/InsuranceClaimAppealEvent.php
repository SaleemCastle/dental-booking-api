<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceClaimAppealEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['occurred_at' => 'immutable_datetime'];

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new \LogicException('Appeal history is append-only.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new \LogicException('Appeal history is append-only.');
    }
}
