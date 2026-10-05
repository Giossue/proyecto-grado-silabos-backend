<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;

/** Plantillas de campo oficiales (I-190): se eligen al agregar un campo y después se modifican como cualquiera. */
class SyllabusTemplateCatalog
{
    private const WEEKS = 16;

    /** @var list<string> */
    private const DISTRIBUTION_COLUMNS = [
        'Semana', 'Contenidos temáticos', 'ACD (h)', 'APE (h)', 'AA (h)',
        'Aprendizaje en contacto con el docente (ACD)', 'Aprendizaje práctico-experimental (APE)',
        'Aprendizaje autónomo (AA)', 'Evaluación de los aprendizajes',
    ];

    /** @return list<array{key: string, title: string, summary: string}> */
    public function options(): array
    {
        return array_map(fn (array $preset): array => [
            'key' => $preset['key'], 'title' => $preset['title'], 'summary' => $preset['summary'],
        ], $this->all());
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->all(), 'key');
    }

    /**
     * Convierte una plantilla elegida en los datos de un campo de texto libre nuevo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function expand(array $data, string $templateId): array
    {
        $preset = collect($this->all())->firstWhere('key', $data['preset'] ?? null);
        if ($preset === null) {
            return $data;
        }

        $label = trim((string) ($data['label'] ?? ''));
        $data['content_type'] = 'free_text';
        $data['label'] = $label !== '' ? $label : $preset['title'];
        $data['key'] = $this->freeKey($templateId, $preset['field_key']);
        $data['from_profile'] = $preset['source'] === FieldDefinition::ORIGIN_PROFILE;
        $data['teacher_editable'] = $preset['adjust'];
        $data['initial_content'] = $preset['initial'] === 'distribucion' ? $this->distribution() : null;

        return $data;
    }

    /** @return list<array{key: string, title: string, summary: string, field_key: string, source: string|null, adjust: bool, initial: string|null}> */
    private function all(): array
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: bool, 6: string|null}> $catalog */
        $catalog = config('syllabus_template.catalog', []);

        return array_map(fn (array $row): array => [
            'key' => $row[0], 'title' => $row[1], 'summary' => $row[2], 'field_key' => $row[3],
            'source' => $row[4], 'adjust' => $row[5], 'initial' => $row[6],
        ], $catalog);
    }

    private function freeKey(string $templateId, string $base): string
    {
        $key = $base;
        $suffix = 2;
        while (FieldDefinition::query()->where('plantilla_id', $templateId)->where('clave', $key)->exists()) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }

    /** @return array<string, mixed> Tabla de planificación: encabezado y una fila por semana, vacía para que el docente la llene. */
    private function distribution(): array
    {
        $paragraph = fn (string $text): array => $text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        $row = fn (array $cells, string $type): array => ['type' => 'tableRow', 'content' => array_map(
            fn (string $text): array => ['type' => $type, 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [$paragraph($text)]],
            $cells,
        )];
        $rows = [$row(self::DISTRIBUTION_COLUMNS, 'tableHeader')];
        for ($week = 1; $week <= self::WEEKS; $week++) {
            $rows[] = $row([(string) $week, ...array_fill(0, count(self::DISTRIBUTION_COLUMNS) - 1, '')], 'tableCell');
        }

        return ['type' => 'doc', 'content' => [['type' => 'table', 'content' => $rows]]];
    }
}
