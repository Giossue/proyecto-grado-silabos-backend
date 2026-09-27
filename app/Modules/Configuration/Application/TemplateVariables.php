<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Academic\Application\CurricularData;
use App\Modules\Syllabus\Application\IdentificationCard;

final class TemplateVariables
{
    /**
     * Sin fotografía devuelve el catálogo de diseño actual. Con fotografía nunca lee
     * conceptos vivos: una revisión conserva nombres, tipos y columnas de su momento.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, array{label: string, source: string, equals?: string, list_key?: string, required?: bool, type?: string, scope?: string, columns?: list<array{key: string, label: string}>}>
     */
    public static function definitions(?array $snapshot = null): array
    {
        $definitions = config('syllabus_variables');
        $curricular = $snapshot === null
            ? CurricularData::definitions()
            : data_get($snapshot, 'academic_context.curricular.definitions', []);
        foreach (is_array($curricular) ? $curricular : [] as $key => $definition) {
            $definitions[CurricularData::VARIABLE_PREFIX.$key] = [
                'label' => $definition['label'],
                'source' => 'academic_context.curricular.values.'.$key,
                'required' => true,
                'type' => $definition['type'],
                'scope' => $definition['scope'],
                'columns' => $definition['columns'] ?? [],
            ];
        }

        return $definitions;
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, string>
     */
    public static function resolve(array $snapshot): array
    {
        $values = [];
        foreach (self::definitions($snapshot) as $key => $definition) {
            $value = data_get($snapshot, $definition['source']);
            $text = is_scalar($value) ? (string) $value : '';
            if (isset($definition['type'])) {
                $text = CurricularData::display($value, $definition);
            }
            if (isset($definition['list_key']) && is_array($value)) {
                $items = [];
                foreach ($value as $item) {
                    $itemText = is_array($item) ? ($item[$definition['list_key']] ?? null) : null;
                    if (is_string($itemText) && trim($itemText) !== '') {
                        $items[] = (count($items) + 1).'. '.trim($itemText);
                    }
                }
                $text = implode("\n", $items);
            }
            if (preg_match('/^identification\.(hours_|total_hours|credits)/', $definition['source']) === 1) {
                $text = IdentificationCard::number($text);
            }
            $values[$key] = isset($definition['equals']) ? ($text === $definition['equals'] ? 'X' : '') : $text;
        }

        return [...$values, ...self::legacyOutcomeAliases($snapshot)];
    }

    /**
     * Compatibilidad I-144: el dato documental confirmado tiene prioridad sobre la
     * base manual cuando ambos están presentes en la misma fotografía del sílabo.
     * Nunca toma el contenido ni las referencias de otra fuente o del catálogo vivo.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string>
     */
    private static function legacyOutcomeAliases(array $snapshot): array
    {
        $definition = data_get($snapshot, 'academic_context.curricular.definitions.resultados_aprendizaje');
        $outcomes = data_get($snapshot, 'academic_context.curricular.values.resultados_aprendizaje');
        if (! is_array($definition) || ($definition['type'] ?? null) !== 'lista'
            || ($definition['scope'] ?? null) !== 'asignatura' || ! is_array($outcomes)) {
            return [];
        }
        $sources = [];
        $locators = [];
        $evidence = data_get($snapshot, 'academic_context.curricular.evidence.resultados_aprendizaje.sources', []);
        foreach (is_array($evidence) ? $evidence : [] as $citation) {
            if (! is_array($citation)) {
                continue;
            }
            $name = is_string($citation['source_name'] ?? null) ? trim($citation['source_name']) : '';
            $locator = is_string($citation['locator'] ?? null) ? trim($citation['locator']) : '';
            if ($name !== '') {
                $sources[] = $name;
            }
            if ($locator !== '') {
                $locators[] = $name === '' ? $locator : $name.' — '.$locator;
            }
        }

        return [
            'resultados_aprendizaje_asignatura' => CurricularData::display($outcomes, $definition),
            'fuente_resultados_aprendizaje' => implode("\n", array_values(array_unique($sources))),
            'ubicacion_resultados_aprendizaje' => implode("\n", array_values(array_unique($locators))),
        ];
    }

    /**
     * Variables de firma resueltas al exportar una revisión aprobada. Sobrescriben las de
     * la fotografía, que se tomó al enviar y no podía conocerlas.
     *
     * @param  array{submitted_on: string, coordinator: string, approved_on: string}  $approval
     * @return array<string, string>
     */
    public static function approval(array $approval): array
    {
        $keys = array_keys(array_filter(
            self::definitions([]),
            fn (array $definition): bool => str_starts_with($definition['source'], 'approval.'),
        ));

        return array_intersect_key(self::resolve(['approval' => $approval]), array_flip($keys));
    }

    /** @return list<array{key: string, label: string, sample: string, type?: string, scope?: string}> */
    public static function catalog(): array
    {
        $samples = self::resolve([
            'identification' => IdentificationCard::sample(),
            'academic_context' => ['subject' => ['learning_outcomes' => [
                'source_name' => 'Proyecto de carrera',
                'source_locator' => 'Página 12, descripción de la asignatura',
                'outcomes' => [
                    ['text' => 'Analiza problemas de la asignatura con criterios fundamentados.'],
                    ['text' => 'Aplica los conocimientos en una solución verificable.'],
                ],
            ]]],
            'approval' => ['submitted_on' => '14-08-2026', 'coordinator' => 'Ing. Galuth García', 'approved_on' => '14-08-2026'],
        ]);
        $catalog = [];
        foreach (self::definitions() as $key => $definition) {
            $item = ['key' => $key, 'label' => $definition['label'], 'sample' => $samples[$key] ?? $definition['label']];
            if (isset($definition['type'], $definition['scope'])) {
                $item['type'] = $definition['type'];
                $item['scope'] = $definition['scope'];
            }
            $catalog[] = $item;
        }

        return $catalog;
    }
}
