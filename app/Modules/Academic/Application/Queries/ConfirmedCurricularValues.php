<?php

namespace App\Modules\Academic\Application\Queries;

use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularValue;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;

/** Proyección de los datos que usaría un sílabo nuevo de la carrera. */
final class ConfirmedCurricularValues
{
    /** @return list<array<string, mixed>> */
    public function forCareer(string $careerId): array
    {
        $subjects = Subject::query()->where('carrera_id', $careerId)
            ->with('learningOutcomeBase.outcomes')->get()->keyBy('id');
        $values = [];
        $importedOutcomes = [];

        foreach (CurricularValue::query()->with('concept')->where('carrera_id', $careerId)->get() as $record) {
            $concept = $record->concept;
            $subject = $record->asignatura_id === null ? null : $subjects->get($record->asignatura_id);
            if (($concept->ambito_concepto === 'asignatura' && $subject === null)
                || ($concept->ambito_concepto === 'carrera' && $record->asignatura_id !== null)) {
                continue;
            }
            if ($concept->clave_concepto === 'resultados_aprendizaje' && $concept->tipo_concepto === 'lista'
                && $concept->ambito_concepto === 'asignatura') {
                $importedOutcomes[$subject->id] = true;
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
                'scope' => $concept->ambito_concepto, 'columns' => $concept->columnas_concepto ?? [],
                'subject_id' => $subject?->id, 'subject_name' => $subject?->nombre,
                'subject_code' => $subject?->codigo_asignatura,
                'value' => $record->valor_curricular, 'evidence' => $evidence,
                'confirmed_at' => $record->confirmado_en->toIso8601String(), 'origin' => 'importacion',
            ];
        }

        foreach ($subjects as $subject) {
            $base = $subject->learningOutcomeBase;
            if ($base === null || isset($importedOutcomes[$subject->id])) {
                continue;
            }
            $values[] = [
                'id' => $base->id, 'key' => 'resultados_aprendizaje', 'concept_key' => 'resultados_aprendizaje',
                'label' => 'Resultados de aprendizaje', 'type' => 'lista',
                'scope' => 'asignatura', 'columns' => [],
                'subject_id' => $subject->id, 'subject_name' => $subject->nombre,
                'subject_code' => $subject->codigo_asignatura,
                'value' => $base->outcomes->pluck('texto_resultado')->values()->all(),
                'evidence' => [['source_name' => $base->nombre_fuente, 'locator' => $base->localizador_fuente, 'quote' => null]],
                'confirmed_at' => $base->confirmado_en->toIso8601String(), 'origin' => 'anterior',
            ];
        }

        usort($values, fn (array $a, array $b): int => [
            $a['subject_name'] ?? '', $a['label'], $a['id'],
        ] <=> [
            $b['subject_name'] ?? '', $b['label'], $b['id'],
        ]);

        return $values;
    }
}
