<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

class AuditLogBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function delete()
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function forceDelete()
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function truncate()
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function __call($method, $parameters)
    {
        if (in_array(strtolower($method), ['updatefrom', 'updateorinsert', 'incrementeach', 'decrementeach'], true)) {
            throw new LogicException('Audit records are append-only.');
        }

        return parent::__call($method, $parameters);
    }
}
