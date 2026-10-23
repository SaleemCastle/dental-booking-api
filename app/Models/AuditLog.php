<?php

namespace App\Models;

use App\Models\Builders\AuditLogBuilder;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'actor_id' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'request_metadata' => 'array',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function newEloquentBuilder($query): AuditLogBuilder
    {
        return new AuditLogBuilder($query);
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new LogicException('Audit records are append-only.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Audit records are append-only.');
    }
}
