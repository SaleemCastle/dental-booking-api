<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceClaimEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['version' => 'integer', 'occurred_at' => 'immutable_datetime'];

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new \LogicException('Insurance claim events are append-only.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new \LogicException('Insurance claim events are append-only.');
    }
}
