<?php

namespace App\Modules\Configuration\Domain\Blocks;

final class Scope
{
    public readonly Counters $counters;

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $props
     * @param  array<string, string>  $variables
     * @param  array<string, mixed>  $aliases
     */
    public function __construct(
        public readonly array $item,
        public readonly array $props,
        public readonly array $variables,
        public readonly array $aliases = [],
        public readonly ?int $index = null,
        ?Counters $counters = null,
    ) {
        $this->counters = $counters ?? new Counters;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function child(array $item, int $index): self
    {
        return new self($item, $this->props, $this->variables, $this->aliases, $index, $this->counters);
    }

    public function alias(string $name, mixed $value, int $index): self
    {
        return new self($this->item, $this->props, $this->variables, [...$this->aliases, $name => $value], $index, $this->counters);
    }

    public function value(string $path): mixed
    {
        return self::read($this->item, $this->resolve($path));
    }

    public function prop(string $path): mixed
    {
        return self::read($this->props, $path);
    }

    public function aliased(string $path): mixed
    {
        [$name, $rest] = array_pad(explode('.', $path, 2), 2, null);
        $value = $this->aliases[$name] ?? null;

        return $rest === null ? $value : self::read(is_array($value) ? $value : [], $rest);
    }

    public function variable(string $key): string
    {
        return $this->variables[$key] ?? '';
    }

    public function resolve(string $path): string
    {
        return implode('.', array_map(function (string $segment): string {
            if (! str_starts_with($segment, '{')) {
                return $segment;
            }
            $found = $this->aliased(substr($segment, 1, -1));

            return is_scalar($found) ? (string) $found : '';
        }, self::segments($path)));
    }

    /**
     * @return list<mixed>
     */
    public function collect(string $path): array
    {
        $values = [$this->item];
        foreach (self::segments($this->resolve($path)) as $segment) {
            $next = [];
            foreach ($values as $value) {
                if (! is_array($value)) {
                    continue;
                }
                if ($segment === '*') {
                    array_push($next, ...array_values($value));
                } elseif (array_key_exists($segment, $value)) {
                    $next[] = $value[$segment];
                }
            }
            $values = $next;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function read(array $data, string $path): mixed
    {
        $value = $data;
        foreach (self::segments($path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        preg_match_all('/\{[^}]*\}|[^.]+/', $path, $matches);

        return $matches[0];
    }
}
