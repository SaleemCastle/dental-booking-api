<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ClinicalNoteChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $noteId,
        public readonly string $action,
        public readonly int $actorId,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
