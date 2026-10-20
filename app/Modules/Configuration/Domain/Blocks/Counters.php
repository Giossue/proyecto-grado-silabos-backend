<?php

namespace App\Modules\Configuration\Domain\Blocks;

final class Counters
{
    /** @var array<string, int> */
    private array $values = [];

    public function next(string $name): int
    {
        $this->values[$name] = ($this->values[$name] ?? 0) + 1;

        return $this->values[$name];
    }
}
