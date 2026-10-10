<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\FieldDocument;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SectionDesigns
{
    /**
     * @return list<string>
     */
    public function baseline(): array
    {
        /**
         * @var list<string> $keys
         */
        $keys = config('syllabus_template.baseline', []);

        return $keys;
    }

    /**
     * @return array{section: array<string, mixed>, definitions: list<array<string, mixed>>}
     */
    public function instantiate(string $key, string $templateId, int $position, ?string $title = null): array
    {
        $design = $this->all()[$key] ?? throw ValidationException::withMessages(['design' => 'El diseño oficial elegido no existe.']);

        $blocks = [];
        $definitions = [];
        foreach ($design['fields'] as $index => [$fieldKey, $label, $responsible, $document]) {
            $blockId = (string) Str::uuid();

            $blocks[] = [
                'id' => $blockId,
                'clave' => $fieldKey,
                'titulo' => $label,
                'configuracion' => [],
                'posicion' => $index + 1,
            ];
            $definitions[] = [
                'plantilla_id' => $templateId,
                'campo_plantilla_id' => $blockId,
                'clave' => $fieldKey,
                'etiqueta' => $label,
                'quien_llena' => $responsible,
                'contenido' => FieldDocument::normalize($document),
                'posicion' => 1,
            ];
        }

        return [
            'section' => [
                'id' => (string) Str::uuid(),
                'clave' => $key,
                'titulo' => $title !== null && trim($title) !== '' ? trim($title) : (($design['numbered'] ?? true) ? $position.'. ' : '').$design['title'],
                'descripcion' => null,
                'posicion' => $position,
                'bloques' => $blocks,
                'orientacion' => $design['orientation'] ?? 'portrait',
            ],
            'definitions' => $definitions,
        ];
    }

    /**
     * @return array<string, array{title: string, summary: string, orientation?: string, numbered?: bool, fields: list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>}>
     */
    private function all(): array
    {
        /**
         * @var array<string, array{title: string, summary: string, orientation?: string, numbered?: bool, fields: list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>}> $designs
         */
        $designs = config('syllabus_template.designs', []);

        return $designs;
    }
}
