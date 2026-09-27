<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Syllabus\Application\IdentificationCard;

final class TemplateVariables
{
    /** @return array<string, array{label: string, source: string, equals?: string, list_key?: string, required?: bool}> */
    public static function definitions(): array
    {
        return config('syllabus_variables');
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, string>
     */
    public static function resolve(array $snapshot): array
    {
        $values = [];
        foreach (self::definitions() as $key => $definition) {
            $value = data_get($snapshot, $definition['source']);
            $text = is_scalar($value) ? (string) $value : '';
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

        return $values;
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
            self::definitions(),
            fn (array $definition): bool => str_starts_with($definition['source'], 'approval.'),
        ));

        return array_intersect_key(self::resolve(['approval' => $approval]), array_flip($keys));
    }

    /** @return list<array{key: string, label: string, sample: string}> */
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
            $catalog[] = ['key' => $key, 'label' => $definition['label'], 'sample' => $samples[$key]];
        }

        return $catalog;
    }
}
