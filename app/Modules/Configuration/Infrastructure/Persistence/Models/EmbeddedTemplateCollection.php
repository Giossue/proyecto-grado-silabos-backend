<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use Illuminate\Support\Collection;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Collection<TKey, TValue>
 */
final class EmbeddedTemplateCollection extends Collection
{
    public function exists(): bool
    {
        return $this->isNotEmpty();
    }

    public function orderBy(string $key, string $direction = 'asc'): static
    {
        $sorted = $direction === 'desc' ? $this->sortByDesc($key) : $this->sortBy($key);

        return new self($sorted->values()->all());
    }

    public function whereKey(mixed $id): static
    {
        return $this->where('id', $id);
    }
}
