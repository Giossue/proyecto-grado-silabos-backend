<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Domain\Blocks\RepeatableTable;
use Closure;
use Illuminate\Validation\Rule;

class DesignRepeatableTableRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $key = 'regex:/^[a-z][a-z0-9_]{0,40}$/';

        return [
            'kind' => ['required', 'string', $key],
            'new' => ['sometimes', 'boolean'],
            'label' => ['required', 'string', 'max:120'],
            'list' => ['nullable', 'string', $key],
            'item' => ['required', 'string', 'max:60'],
            'min' => ['required', 'integer', 'min:0', 'max:100'],
            'max' => ['required', 'integer', 'min:1', 'max:100', 'gte:min'],
            'initial' => ['required', 'integer', 'gte:min', 'lte:max'],
            'band' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'columns' => ['required', 'array', 'min:1', 'max:24'],
            'columns.*.key' => ['nullable', 'string', $key, 'distinct'],
            'columns.*.title' => ['required', 'string', 'max:300'],
            'columns.*.type' => ['required', Rule::in(RepeatableTable::TYPES)],
            'columns.*.max_length' => ['exclude_unless:columns.*.type,text', 'required', 'integer', 'min:1', 'max:20000'],
            'columns.*.multiline' => ['exclude_unless:columns.*.type,text', 'sometimes', 'boolean'],
            'columns.*.options' => ['exclude_unless:columns.*.type,choice', 'required', 'array', 'min:1', 'max:30'],
            'columns.*.options.*.label' => ['exclude_unless:columns.*.type,choice', 'required', 'string', 'max:300', $this->distinctInColumn()],
            'columns.*.options.*.value' => ['exclude_unless:columns.*.type,choice', 'nullable', 'string', $key],
            'columns.*.maximum' => ['exclude_unless:columns.*.type,number', 'required', 'numeric', 'gt:0', 'lt:1000000000'],
            'columns.*.decimals' => ['exclude_unless:columns.*.type,number', 'required', 'integer', 'min:0', 'max:6'],
            'columns.*.width' => ['nullable', 'integer', 'min:20', 'max:1200'],
            'columns.*.required' => ['sometimes', 'boolean'],
            'extras' => ['sometimes', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'columns.required' => 'Agregue al menos una columna.',
            'columns.min' => 'Agregue al menos una columna.',
            'columns.max' => 'La tabla admite hasta 24 columnas.',
            'columns.*.title.required' => 'Escriba el nombre de la columna.',
            'columns.*.options.required' => 'Agregue al menos una opción.',
            'columns.*.options.min' => 'Agregue al menos una opción.',
            'columns.*.options.*.label.required' => 'Escriba el texto de la opción.',
            'max.gte' => 'El máximo de filas no puede ser menor que el mínimo.',
            'initial.gte' => '«Al empezar» debe estar entre el mínimo y el máximo.',
            'initial.lte' => '«Al empezar» debe estar entre el mínimo y el máximo.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kind' => 'nombre interno de la tabla',
            'new' => 'tabla nueva',
            'columns' => 'columnas',
            'columns.*.options' => 'opciones',
            'columns.*.options.*.label' => 'texto de la opción',
            'extras' => 'datos guardados de la tabla',
            'label' => 'título de la tabla',
            'list' => 'nombre interno de las filas',
            'item' => 'nombre de cada fila',
            'min' => 'mínimo de filas',
            'max' => 'máximo de filas',
            'initial' => 'filas al empezar',
            'band' => 'color de filas alternas',
            'columns.*.key' => 'nombre interno de la columna',
            'columns.*.title' => 'nombre de la columna',
            'columns.*.type' => 'tipo de celda',
            'columns.*.max_length' => 'máximo de caracteres',
            'columns.*.multiline' => 'varias líneas',
            'columns.*.options.*.value' => 'clave de la opción',
            'columns.*.maximum' => 'número máximo',
            'columns.*.decimals' => 'decimales',
            'columns.*.width' => 'ancho',
            'columns.*.required' => 'obligatoria',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function design(): array
    {
        /** @var array<string, mixed> $design */
        $design = $this->safe()->except('new');
        $design['columns'] = self::inInputOrder($design['columns'] ?? [], $this->input('columns'));
        foreach ($design['columns'] as $index => $column) {
            if (is_array($column['options'] ?? null)) {
                $raw = $this->input("columns.{$index}.options");
                $design['columns'][$index]['options'] = self::inInputOrder($column['options'], $raw);
            }
        }

        return $design;
    }

    /**
     * @param  array<int|string, mixed>  $validated
     * @return list<mixed>
     */
    private static function inInputOrder(array $validated, mixed $raw): array
    {
        $ordered = [];
        foreach (is_array($raw) ? array_keys($raw) : [] as $index) {
            if (array_key_exists($index, $validated)) {
                $ordered[] = $validated[$index];
            }
        }

        return $ordered;
    }

    private function distinctInColumn(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (preg_match('/^columns\.(\d+)\.options\.(\d+)\.label$/', $attribute, $match) !== 1 || ! is_string($value)) {
                return;
            }
            $normalize = fn (mixed $label): string => is_string($label) ? mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label))) : '';
            foreach ((array) $this->input("columns.{$match[1]}.options", []) as $position => $option) {
                if ((int) $position < (int) $match[2] && is_array($option) && $normalize($option['label'] ?? null) === $normalize($value)) {
                    $fail('La opción está repetida.');

                    return;
                }
            }
        };
    }
}
