<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use Illuminate\Validation\ValidationException;

final class TableFieldType implements FieldType
{
    private const MAX_COLUMNS = 12;

    private const MAX_ROWS = 60;

    private const MAX_CELL = 2000;

    public function key(): string
    {
        return 'tabla';
    }

    public function label(): string
    {
        return 'Tabla';
    }

    public function configRules(): array
    {
        return [
            'columns' => ['required', 'array', 'min:1', 'max:'.self::MAX_COLUMNS],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
        ];
    }

    public function normalizeConfig(array $config): array
    {
        return [
            'columns' => $this->axis($config['columns'] ?? null, 'columns', self::MAX_COLUMNS),
            'rows' => $this->axis($config['rows'] ?? null, 'rows', self::MAX_ROWS),
        ];
    }

    public function defaultValue(array $config): mixed
    {
        $value = [];
        foreach ($this->axis($config['rows'] ?? [], 'rows', self::MAX_ROWS) as $row) {
            foreach ($this->axis($config['columns'] ?? [], 'columns', self::MAX_COLUMNS) as $column) {
                $value[$row['key']][$column['key']] = '';
            }
        }

        return $value;
    }

    public function validateValue(mixed $value, array $config, string $attribute = 'value'): mixed
    {
        if (! is_array($value)) {
            $this->fail($attribute, 'La tabla no tiene un formato válido.');
        }
        $normalized = $this->defaultValue($config);
        foreach ($value as $rowKey => $cells) {
            if (! isset($normalized[$rowKey]) || ! is_array($cells)) {
                $this->fail($attribute, 'La tabla contiene una fila que no existe en la plantilla.');
            }
            foreach ($cells as $columnKey => $text) {
                if (! array_key_exists($columnKey, $normalized[$rowKey]) || ! (is_string($text) || $text === null)) {
                    $this->fail($attribute, 'La tabla contiene una celda que no existe en la plantilla.');
                }
                if (mb_strlen((string) $text) > self::MAX_CELL) {
                    $this->fail($attribute, 'Una celda de la tabla es demasiado extensa.');
                }
                $normalized[$rowKey][$columnKey] = trim((string) $text);
            }
        }

        return $normalized;
    }

    public function isEmpty(mixed $value, array $config): bool
    {
        if (! is_array($value)) {
            return true;
        }
        foreach ($value as $cells) {
            foreach (is_array($cells) ? $cells : [] as $text) {
                if (is_string($text) && trim($text) !== '') {
                    return false;
                }
            }
        }

        return true;
    }

    public function teacherEditable(): bool
    {
        return true;
    }

    /** @return list<array{key: string, label: string}> */
    private function axis(mixed $items, string $attribute, int $max): array
    {
        if (! is_array($items) || $items === [] || count($items) > $max) {
            $this->fail($attribute, 'La tabla necesita entre 1 y '.$max.' elementos.');
        }
        $result = [];
        $seen = [];
        foreach ($items as $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;
            $label = is_array($item) ? ($item['label'] ?? null) : null;
            if (! is_string($key) || ! preg_match('/^[a-z0-9_]{1,40}$/', $key) || ! is_string($label) || trim($label) === '') {
                $this->fail($attribute, 'Cada elemento de la tabla necesita una clave y una etiqueta válidas.');
            }
            if (isset($seen[$key])) {
                $this->fail($attribute, 'La tabla tiene claves repetidas.');
            }
            $seen[$key] = true;
            $result[] = ['key' => $key, 'label' => trim($label)];
        }

        return $result;
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
