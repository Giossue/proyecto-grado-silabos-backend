<?php

namespace App\Support\Queue;

use Illuminate\Queue\Middleware\WithoutOverlapping;

trait RunsOnce
{
    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->dontRelease()->expireAfter($this->timeout + 30)];
    }
}
