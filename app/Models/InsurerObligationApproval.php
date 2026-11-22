<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class InsurerObligationApproval extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['command_key', 'request_hash'];

    protected $casts = ['approved_at' => 'immutable_datetime', 'approved_amount' => 'decimal:2'];

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new LogicException('Insurer obligation approvals are append-only.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Insurer obligation approvals are append-only.');
    }
}
