<?php

namespace App\Support\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use SortDirection;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class ExplicitColumnBuilder extends Builder
{
    private function mapped(mixed $column): mixed
    {
        if (! is_string($column) || ! method_exists($this->model, 'mappedColumn')) {
            return $column;
        }

        return call_user_func([$this->model, 'mappedColumn'], $column);
    }

    private function mappedColumns(mixed $columns): mixed
    {
        if (is_string($columns)) {
            return $this->mapped($columns);
        }

        if (! is_array($columns)) {
            return $columns;
        }

        return array_map(fn (mixed $column): mixed => $this->mapped($column), $columns);
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_array($column)) {
            $column = collect($column)->mapWithKeys(fn (mixed $value, int|string $key): array => [$this->mapped($key) => $value])->all();
        } else {
            $column = $this->mapped($column);
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $this->query->whereIn($this->mapped($column), $values, $boolean, $not);

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @return $this
     */
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        $this->query->whereNotIn($this->mapped($column), $values, $boolean);

        return $this;
    }

    /**
     * @param  string|array<int, string>  $columns
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        $this->query->whereNull($this->mappedColumns($columns), $boolean, $not);

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  'asc'|'desc'|SortDirection  $direction
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->query->orderBy($this->mapped($column), $direction);

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @return $this
     */
    public function orderByDesc($column)
    {
        $this->query->orderByDesc($this->mapped($column));

        return $this;
    }

    public function latest($column = null)
    {
        return parent::latest($column === null ? null : $this->mapped($column));
    }

    public function oldest($column = null)
    {
        return parent::oldest($column === null ? null : $this->mapped($column));
    }

    /**
     * @param  array<int, string>|string  $columns
     * @return $this
     */
    public function select($columns = ['*'])
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $this->query->select($this->mappedColumns($columns));

        return $this;
    }

    /**
     * @param  array<int, string>|string  $column
     * @return $this
     */
    public function addSelect($column)
    {
        $column = is_array($column) ? $column : func_get_args();
        $this->query->addSelect($this->mappedColumns($column));

        return $this;
    }

    /**
     * @param  array<int, string>|string  $columns
     * @return EloquentCollection<int, TModel>
     */
    public function get($columns = ['*'])
    {
        $builder = $this->applyScopes();
        $models = $builder->getModels($this->mappedColumns($columns));

        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->applyAfterQueryCallbacks($builder->getModel()->newCollection($models));
    }

    /** @param string|Expression $column */
    public function value($column)
    {
        return parent::value($this->mapped($column));
    }

    /**
     * @param  string|Expression  $column
     * @param  string|null  $key
     * @return Collection<array-key, mixed>
     */
    public function pluck($column, $key = null)
    {
        return parent::pluck($this->mapped($column), $key === null ? null : $this->mapped($key));
    }

    /**
     * @param  string|Expression  $column
     * @return mixed
     */
    public function max($column)
    {
        return $this->toBase()->max($this->mapped($column));
    }

    /**
     * @param  string|Expression  $column
     * @return mixed
     */
    public function min($column)
    {
        return $this->toBase()->min($this->mapped($column));
    }

    /**
     * @param  string|Expression  $column
     * @return int|float|string
     */
    public function sum($column)
    {
        return $this->toBase()->sum($this->mapped($column));
    }

    /**
     * @param  string|Expression  $column
     * @return mixed
     */
    public function avg($column)
    {
        return $this->toBase()->avg($this->mapped($column));
    }

    /**
     * @param  string|Expression  $column
     * @return mixed
     */
    public function average($column)
    {
        return $this->toBase()->average($this->mapped($column));
    }

    /** @param string|Expression $column @param int|float $amount @param array<string, mixed> $extra */
    public function increment($column, $amount = 1, array $extra = [])
    {
        return parent::increment($this->mapped($column), $amount, $this->mappedValues($extra));
    }

    /** @param string|Expression $column @param int|float $amount @param array<string, mixed> $extra */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        return parent::decrement($this->mapped($column), $amount, $this->mappedValues($extra));
    }

    /** @param array<string, mixed> $values */
    public function update(array $values)
    {
        return parent::update($this->mappedValues($values));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function mappedValues(array $values): array
    {
        $mapped = [];
        foreach ($values as $column => $value) {
            $mapped[$this->mapped($column)] = $value;
        }

        return $mapped;
    }
}
