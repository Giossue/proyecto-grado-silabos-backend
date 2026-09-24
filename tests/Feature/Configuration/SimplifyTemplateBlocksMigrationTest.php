<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** I-138: la plantilla existente (formato anterior) se convierte de forma idempotente. */
class SimplifyTemplateBlocksMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_converts_the_previous_format_and_is_idempotent(): void
    {
        $template = app(SyllabusTemplateProvisioner::class)->createWithOfficialFormat();
        $document = $template->documento;
        foreach ($document['secciones'] as $s => $section) {
            foreach ($section['bloques'] as $b => $block) {
                $old = &$document['secciones'][$s]['bloques'][$b];
                $old['tipo'] = 'narrativa';
                if ($block['titulo'] === 'Indicadores de evaluación') {
                    $old['tipo'] = 'repetible';
                    $old['configuracion'] = ['content_type' => 'table', 'table' => [
                        'columns' => [['key' => 'indicador', 'label' => 'Indicadores', 'type' => 'text']],
                        'totals' => ['enabled' => false, 'label' => 'Total'],
                        'repeat' => ['enabled' => false, 'label' => 'Unidad'],
                    ]];
                    $indicators = $block['id'];
                }
                if ($block['titulo'] === 'Bibliografía básica') {
                    $old['configuracion']['table']['columns'][2]['sum'] = true;
                    $old['configuracion']['table']['repeat'] = ['enabled' => false, 'label' => 'Unidad'];
                }
                if ($section['clave'] === 'revision') {
                    $old = ['id' => $block['id'], 'clave' => 'revision_principal', 'tipo' => 'flujo', 'titulo' => 'Estado de revisión',
                        'configuracion' => ['content_type' => 'flow'], 'posicion' => 1];
                    $flow = $block['id'];
                }
                unset($old);
            }
        }
        $template->update(['documento' => $document]);
        foreach ([[$indicators, 'indicadores_evaluacion', 'repetible', null], [$flow, 'estado_revision', 'referencia_maestra', 'flujo']] as [$blockId, $key, $type, $origin]) {
            FieldDefinition::query()->create([
                'plantilla_id' => $template->id, 'bloque_plantilla_id' => $blockId, 'clave' => $key, 'etiqueta' => $key,
                'tipo' => $type, 'heredado' => $origin !== null, 'origen_maestro' => $origin, 'editable_docente' => $origin === null,
                'ia_habilitada' => false, 'posicion' => 1,
            ]);
        }

        $migration = require database_path('migrations/2026_09_24_000106_simplify_template_blocks.php');
        $migration->up();
        $first = DB::table('plantilla_silabo')->value('documento_plantilla');
        $migration->up();
        $this->assertSame($first, DB::table('plantilla_silabo')->value('documento_plantilla'), 'Idempotente.');

        $blocks = SyllabusTemplate::institutional()->blocks()->keyBy('id');
        $this->assertFalse($blocks->contains(fn ($block) => array_key_exists('tipo', $block->toDocument())));
        $this->assertSame('fixed', $blocks[$flow]->configuredContentType());
        $this->assertSame('Revisión y aprobación', $blocks[$flow]->titulo);
        $this->assertNull(FieldDefinition::query()->where('clave', 'estado_revision')->first());
        $this->assertSame('fixed', $blocks[$indicators]->configuredContentType());
        $this->assertStringContainsString('Evaluación parcial', json_encode($blocks[$indicators]->configuracion['document'], JSON_UNESCAPED_UNICODE));
        $this->assertFalse(FieldDefinition::query()->where('clave', 'indicadores_evaluacion')->firstOrFail()->editable_docente);
        $bibliography = $blocks->first(fn ($block) => $block->titulo === 'Bibliografía básica')->configuracion['table'];
        $this->assertSame(['enabled' => false], $bibliography['repeat']);
        $this->assertFalse(collect($bibliography['columns'])->firstWhere('key', 'anio')['sum']);
    }
}
