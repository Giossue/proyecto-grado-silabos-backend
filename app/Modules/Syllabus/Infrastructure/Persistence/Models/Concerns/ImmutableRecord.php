<?php

namespace App\Modules\Syllabus\Infrastructure\Persistence\Models\Concerns;

use LogicException;

trait ImmutableRecord
{
    protected static function bootImmutableRecord(): void
    {
        static::updating(function (self $model): void {
            $allowed = $model->writeOnceColumns();
            foreach (array_keys($model->getDirty()) as $column) {
                if (! in_array($column, $allowed, true) || $model->getOriginal($column) !== null) {
                    throw new LogicException('El registro histórico es inmutable.');
                }
            }
        });
        static::deleting(fn (self $model) => throw new LogicException('El registro histórico no puede eliminarse.'));
    }

    /** @return list<string> Columnas que pueden pasar de NULL a un valor una sola vez. */
    abstract public function writeOnceColumns(): array;
}
