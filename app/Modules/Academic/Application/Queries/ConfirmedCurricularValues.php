<?php

namespace App\Modules\Academic\Application\Queries;

use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularValue;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;

final class ConfirmedCurricularValues
{
    /** @return list<array<string, mixed>> */
    public function forCareer(string $careerId): array
    {
        $subjects = Subject::query()->where('carrera_id', $careerId)->get()->keyBy('id');
        $values = [];

        foreach (CurricularValue::query()->with('concept')->where('carrera_id', $careerId)->get() as $record) {
            $concept = $record->concept;
            $subject = $subjects->get($record->asignatura_id);
            if ($subject === null) {
                continue;
            }
            $evidence = [];
            foreach ($record->evidencia_curricular as $citation) {
                $evidence[] = [
                    'source_name' => (string) ($citation['source_name'] ?? 'Documento de importación'),
                    'locator' => (string) ($citation['locator'] ?? ''),
                    'quote' => is_string($citation['quote'] ?? null) ? $citation['quote'] : null,
                ];
            }
            $values[] = [
                'id' => $record->id, 'key' => $concept->clave_concepto, 'concept_key' => $concept->clave_concepto,
                'label' => $concept->etiqueta_concepto, 'type' => $concept->tipo_concepto,
                'columns' => $concept->columnas_concepto ?? [],
                'subject_id' => $subject->id, 'subject_name' => $subject->nombre,
                'subject_code' => $subject->codigo_asignatura,
                'value' => $record->valor_curricular, 'evidence' => $evidence,
                'confirmed_at' => $record->confirmado_en->toIso8601String(), 'origin' => 'importacion',
            ];
        }

        usort($values, fn (array $a, array $b): int => [
            $a['subject_name'], $a['label'], $a['id'],
        ] <=> [
            $b['subject_name'], $b['label'], $b['id'],
        ]);

        return $values;
    }
}
