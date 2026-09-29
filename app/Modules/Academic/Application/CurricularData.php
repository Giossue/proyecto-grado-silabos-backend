<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularConcept;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularValue;

/** Lectura de datos confirmados; la resolución documental trabaja solo con su fotografía. */
final class CurricularData
{
    public const VARIABLE_PREFIX = 'curricular_';

    /** @return array<string, array{key: string, label: string, type: string, columns: list<array{key: string, label: string}>}> */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (CurricularConcept::query()->orderBy('clave_concepto')->get() as $concept) {
            $definitions[$concept->clave_concepto] = [
                'key' => $concept->clave_concepto,
                'label' => $concept->etiqueta_concepto,
                'type' => $concept->tipo_concepto,
                'columns' => $concept->columnas_concepto ?? [],
            ];
        }

        return $definitions;
    }

    /** @return array{definitions: array<string, array{key: string, label: string, type: string, columns: list<array{key: string, label: string}>}>, values: array<string, mixed>, evidence: array<string, array<string, mixed>>} */
    public function snapshot(string $careerId, string $subjectId): array
    {
        $definitions = self::definitions();
        $values = [];
        $evidence = [];
        $records = CurricularValue::query()->with('concept')
            ->where('carrera_id', $careerId)
            ->where('asignatura_id', $subjectId)
            ->orderBy('concepto_id')->get();
        foreach ($records as $record) {
            $concept = $record->concept;
            $key = $concept->clave_concepto;
            $values[$key] = $record->valor_curricular;
            $evidence[$key] = [
                'sources' => $record->evidencia_curricular,
                'confirmed_at' => $record->confirmado_en->toIso8601String(),
                'confirmed_by' => $record->confirmado_por,
            ];
        }

        return ['definitions' => $definitions, 'values' => $values, 'evidence' => $evidence];
    }

    /** @param array<string, mixed> $definition */
    public static function display(mixed $value, array $definition): string
    {
        if (($definition['type'] ?? null) === 'texto') {
            return is_string($value) ? $value : '';
        }
        if (! is_array($value)) {
            return '';
        }
        $lines = [];
        foreach ($value as $row) {
            if (($definition['type'] ?? null) === 'lista' && is_string($row) && trim($row) !== '') {
                $lines[] = (count($lines) + 1).'. '.trim($row);
            } elseif (($definition['type'] ?? null) === 'tabla' && is_array($row)) {
                $cells = [];
                foreach ($definition['columns'] ?? [] as $column) {
                    $cell = $row[$column['key']] ?? null;
                    if (is_string($cell) && trim($cell) !== '') {
                        $cells[] = $column['label'].': '.trim($cell);
                    }
                }
                if ($cells !== []) {
                    $lines[] = implode(' · ', $cells);
                }
            }
        }

        return implode("\n", $lines);
    }
}
