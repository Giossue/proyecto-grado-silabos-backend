<?php

namespace App\Modules\Academic\Application;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Contrato común de extracción y aplicación. Los errores de una fila se conservan
 * en la propuesta para que no desaparezcan silenciosamente durante la revisión.
 */
final class CurricularImportProposal
{
    /** @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function normalize(array $proposal): array
    {
        $data = Validator::make($proposal, [
            'definitions' => ['present', 'array', 'list', 'max:100'],
            // «scope» se admite solo en propuestas anteriores a I-160 y se descarta.
            'definitions.*' => ['array:key,label,type,scope,columns'],
            'definitions.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,99}$/', 'distinct:strict'],
            'definitions.*.label' => ['required', 'string', 'max:180'],
            'definitions.*.type' => ['required', 'in:texto,lista,tabla'],
            'definitions.*.columns' => ['sometimes', 'nullable', 'array', 'list', 'max:30'],
            'definitions.*.columns.*' => ['array:key,label'],
            'definitions.*.columns.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,99}$/'],
            'definitions.*.columns.*.label' => ['required', 'string', 'max:180'],
            'values' => ['present', 'array', 'list', 'max:10000'],
            'values.*' => ['array:id,concept_key,subject_id,subject_name,value,evidence,issue,issue_code'],
            'values.*.id' => ['required', 'uuid', 'distinct:strict'],
            'values.*.concept_key' => ['required', 'string'],
            'values.*.subject_id' => ['present', 'nullable', 'uuid'],
            'values.*.subject_name' => ['present', 'nullable', 'string', 'max:500'],
            'values.*.value' => ['present'],
            'values.*.evidence' => ['present', 'array', 'list', 'max:100'],
            'values.*.evidence.*' => ['array:source_id,locator,quote'],
            'values.*.evidence.*.source_id' => ['required', 'uuid'],
            'values.*.evidence.*.locator' => ['required', 'string', 'max:1000'],
            'values.*.evidence.*.quote' => ['required', 'string', 'max:20000'],
            'values.*.issue' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'values.*.issue_code' => ['sometimes', 'nullable', 'in:subject_unmatched,invalid_value,invalid_evidence,conflict,source_conflict'],
            'issues' => ['present', 'array', 'list', 'max:1000'],
            'issues.*' => ['required', 'string', 'max:4000'],
        ])->validate();

        $definitions = [];
        foreach ($data['definitions'] as $index => $definition) {
            $columns = $definition['columns'] ?? null;
            if ($definition['type'] === 'tabla') {
                if ($columns === null || $columns === [] || count(array_unique(array_column($columns, 'key'))) !== count($columns)) {
                    throw ValidationException::withMessages(["definitions.{$index}.columns" => 'La tabla necesita columnas con claves únicas.']);
                }
                $normalized = [];
                foreach ($columns as $column) {
                    if (! is_string($column['key']) || ! is_string($column['label'])) {
                        throw ValidationException::withMessages(["definitions.{$index}.columns" => 'Cada columna necesita clave y nombre.']);
                    }
                    $normalized[] = ['key' => $column['key'], 'label' => trim($column['label'])];
                }
                $columns = $normalized;
            } elseif ($columns !== null && $columns !== []) {
                throw ValidationException::withMessages(["definitions.{$index}.columns" => 'Solo las tablas admiten columnas.']);
            } else {
                $columns = null;
            }
            $definitions[$definition['key']] = [
                'key' => $definition['key'], 'label' => trim($definition['label']),
                'type' => $definition['type'], 'columns' => $columns,
            ];
        }
        $rows = [];
        foreach ($data['values'] as $row) {
            $definition = $definitions[$row['concept_key']] ?? null;
            if ($definition === null) {
                throw ValidationException::withMessages(['values' => 'Un valor referencia un concepto que no está definido en la propuesta.']);
            }
            $row['issue'] ??= null;
            $row['issue_code'] ??= $row['issue'] !== null ? 'conflict' : null;
            try {
                $row['value'] = $this->value($definition['type'], $definition['columns'], $row['value']);
            } catch (ValidationException) {
                $row['issue_code'] = 'invalid_value';
                $row['issue'] = 'El contenido no cumple la estructura del concepto.';
            }
            if ($row['evidence'] === [] && $row['issue_code'] === null) {
                $row['issue_code'] = 'invalid_evidence';
                $row['issue'] = 'El contenido no incluye evidencia verificable.';
            }
            // Todo dato del Proyecto de carrera es de una asignatura (I-160).
            if ($row['subject_id'] === null && $row['issue_code'] === null) {
                $row['issue_code'] = 'subject_unmatched';
                $row['issue'] = 'Seleccione la asignatura que corresponde a este contenido.';
            }
            $rows[] = $row;
        }

        return ['definitions' => array_values($definitions), 'values' => $rows, 'issues' => $data['issues']];
    }

    /** @param list<array{key: string, label: string}>|null $columns
     * @return string|list<string>|list<array<string, string>>
     */
    public function value(string $type, ?array $columns, mixed $value): string|array
    {
        if ($type === 'texto') {
            return $this->text($value);
        }
        if (! in_array($type, ['lista', 'tabla'], true) || ! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 1000) {
            throw ValidationException::withMessages(['value' => 'Se necesita una colección no vacía con la estructura definida.']);
        }
        if ($type === 'lista') {
            return array_map(fn (mixed $item): string => $this->text($item), $value);
        }
        $keys = array_column($columns ?? [], 'key');
        if ($keys === []) {
            throw ValidationException::withMessages(['value' => 'La tabla no tiene columnas definidas.']);
        }
        $rows = [];
        foreach ($value as $row) {
            if (! is_array($row) || count($row) !== count($keys) || array_diff($keys, array_keys($row)) !== []) {
                throw ValidationException::withMessages(['value' => 'Las celdas deben corresponder exactamente a las columnas definidas.']);
            }
            $normalized = [];
            foreach ($keys as $key) {
                // Las celdas vacías son válidas; no se inventa contenido ausente.
                $normalized[$key] = $this->text($row[$key], allowEmpty: true);
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    private function text(mixed $value, bool $allowEmpty = false): string
    {
        if (! is_string($value) || (! $allowEmpty && trim($value) === '') || mb_strlen($value) > 20000) {
            throw ValidationException::withMessages(['value' => 'El contenido debe ser texto válido dentro del límite permitido.']);
        }

        return trim($value);
    }
}
