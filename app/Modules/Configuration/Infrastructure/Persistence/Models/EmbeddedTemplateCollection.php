<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use Illuminate\Support\Collection;

/**
 * Colección que conserva la interfaz de lectura usada antes por relaciones Eloquent,
 * aunque secciones y bloques ahora vivan dentro del documento JSONB.
 *
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

    public function get($key = null, $default = null): mixed
    {
        return func_num_args() === 0 ? $this : parent::get($key, $default);
    }

    public function with($relations): static
    {
        return $this;
    }

    public function orderBy(string $key, string $direction = 'asc'): static
    {
        $sorted = $direction === 'desc' ? $this->sortByDesc($key) : $this->sortBy($key);

        return new static($sorted->values()->all());
    }

    public function whereKey(mixed $id): static
    {
        return $this->where('id', $id);
    }
}
