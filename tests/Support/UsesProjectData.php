<?php

namespace Tests\Support;

use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularConcept;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Support\Str;

/** La plantilla manda (I-155): un dato del Proyecto de carrera cuenta solo si la hoja lo usa. */
trait UsesProjectData
{
    /** Declara el dato, si hace falta, y lo agrega como parte fija de la primera sección. */
    private function useProjectData(string $key, string $label, string $type = 'lista'): CurricularConcept
    {
        $concept = CurricularConcept::query()->firstOrCreate(['clave_concepto' => $key], [
            'etiqueta_concepto' => $label, 'tipo_concepto' => $type,
            'columnas_concepto' => $type === 'tabla' ? [['key' => 'texto', 'label' => 'Texto']] : null,
        ]);
        if (! SyllabusTemplate::query()->exists()) {
            $this->seed(SyllabusTemplateSeeder::class);
        }
        $template = SyllabusTemplate::institutional();
        $section = $template->sections()->sortBy('posicion')->firstOrFail();
        $blocks = $section->blocks()->map->toDocument()->values()->all();
        $blocks[] = [
            'id' => (string) Str::uuid(), 'clave' => 'proyecto_'.$key, 'titulo' => '', 'posicion' => count($blocks) + 1,
            'configuracion' => ['content_type' => 'fixed', 'document' => ['type' => 'doc', 'content' => [[
                'type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'curricular_'.$key, 'label' => 'curricular_'.$key]]],
            ]]]],
        ];
        $template->updateSectionDocument($section->id, ['bloques' => $blocks]);

        return $concept;
    }
}
