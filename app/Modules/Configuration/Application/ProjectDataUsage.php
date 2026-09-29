<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Academic\Application\CurricularData;
use App\Modules\Configuration\Domain\TemplateDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;

/**
 * Datos @ProyectoCarrera que usa la plantilla (I-155). Un dato se usa cuando la hoja de
 * una parte lo muestra (`variable curricular_<clave>`) o cuando una pregunta del docente
 * toma de él sus opciones. La relación no se guarda: se lee de la plantilla vigente.
 */
final class ProjectDataUsage
{
    /**
     * @return array<string, array{key: string, label: string, type: string, scope: string, columns: list<array{key: string, label: string}>, sections: list<string>}>
     */
    public static function used(?SyllabusTemplate $template = null): array
    {
        $template ??= SyllabusTemplate::query()->first();
        if ($template === null) {
            return [];
        }
        $declared = CurricularData::definitions();
        $used = [];
        foreach ($template->sections() as $section) {
            foreach ($section->blocks() as $block) {
                foreach (self::keysIn($block->configuracion) as $key) {
                    if (! isset($declared[$key])) {
                        continue;
                    }
                    $used[$key] ??= [...$declared[$key], 'sections' => []];
                    if (! in_array($section->titulo, $used[$key]['sections'], true)) {
                        $used[$key]['sections'][] = $section->titulo;
                    }
                }
            }
        }
        ksort($used);

        return $used;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    private static function keysIn(array $configuration): array
    {
        $variables = [];
        $document = $configuration['document'] ?? null;
        if (is_array($document)) {
            foreach (TemplateDocument::nodes($document, 'variable') as $node) {
                $variables[] = $node['attrs']['id'] ?? null;
            }
            foreach (TemplateDocument::nodes($document, 'field') as $node) {
                array_push($variables, ...CurricularFieldOptions::fromAttributes($node['attrs'] ?? []));
            }
        } else {
            foreach ($configuration['options_sources'] ?? [] as $sources) {
                array_push($variables, ...(is_array($sources) ? $sources : [$sources]));
            }
        }
        $keys = [];
        foreach ($variables as $variable) {
            if (is_string($variable) && str_starts_with($variable, CurricularData::VARIABLE_PREFIX)) {
                $keys[] = substr($variable, strlen(CurricularData::VARIABLE_PREFIX));
            }
        }

        return array_values(array_unique($keys));
    }
}
