<?php

use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Domain\TableLayout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-138: constructor de plantilla más simple. Idempotente: volver a ejecutarla no cambia
 * una plantilla ya convertida.
 *
 * - El bloque guarda su tipo una sola vez (`configuracion.content_type`); se quita `tipo`.
 * - Se retira el bloque «Estado de revisión» (`flow`): su lugar lo toma una tabla fija con
 *   las variables de firma. Sus definiciones solo tenían valores del sistema.
 * - «Indicadores de evaluación» y «Escala de valoración» pasan a contenido fijo con los
 *   valores UEB. Sus campos quedan retirados (no se borran filas del docente).
 * - Las tablas no guardan nombres de repetición o totales apagados, ni suman semana o año.
 */
return new class extends Migration
{
    private const FIXED_TABLES = [
        'indicadores_evaluacion' => 'evaluationIndicators',
        'componentes_evaluacion' => 'gradingScale',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('plantilla_silabo')->lockForUpdate()->get(['id', 'documento_plantilla']) as $template) {
                $this->convert((string) $template->id, json_decode((string) $template->documento_plantilla, true) ?: []);
            }
        });
    }

    /**
     * Solo devuelve `tipo` a cada bloque para que las migraciones anteriores puedan
     * revertirse. Los contenidos fijos y el estado de revisión retirado no se reconstruyen.
     */
    public function down(): void
    {
        foreach (DB::table('plantilla_silabo')->get(['id', 'documento_plantilla']) as $template) {
            $document = json_decode((string) $template->documento_plantilla, true) ?: [];
            foreach ($document['secciones'] ?? [] as $s => $section) {
                foreach ($section['bloques'] ?? [] as $b => $block) {
                    $document['secciones'][$s]['bloques'][$b]['tipo'] = in_array($block['configuracion']['content_type'] ?? null, ['table', 'bulleted_list', 'numbered_list'], true)
                        ? 'repetible'
                        : 'narrativa';
                }
            }
            DB::table('plantilla_silabo')->where('id', $template->id)->update([
                'documento_plantilla' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /** @param array<string, mixed> $document */
    private function convert(string $templateId, array $document): void
    {
        $fields = DB::table('definiciones_campo')->where('plantilla_id', $templateId)
            ->get(['id', 'bloque_plantilla_id', 'clave_definicion_campo', 'editable_docente', 'ia_habilitada'])
            ->groupBy('bloque_plantilla_id');
        $flowFieldIds = [];
        $detach = [];
        foreach ($document['secciones'] ?? [] as $s => $section) {
            foreach ($section['bloques'] ?? [] as $b => $block) {
                $configuration = is_array($block['configuracion'] ?? null) ? $block['configuracion'] : [];
                $configuration['content_type'] ??= match ($block['tipo'] ?? null) {
                    'repetible' => 'table',
                    'flujo' => 'flow',
                    default => 'text',
                };
                unset($block['tipo']);
                $blockFields = $fields->get($block['id'], collect());

                if ($configuration['content_type'] === 'flow') {
                    $flowFieldIds = [...$flowFieldIds, ...$blockFields->pluck('id')->all()];
                    $block['titulo'] = 'Revisión y aprobación';
                    $configuration = ['content_type' => 'fixed', 'document' => TemplateDocumentDefaults::signatures()];
                }

                $fixed = $blockFields->first(fn (object $field): bool => isset(self::FIXED_TABLES[$field->clave_definicion_campo]));
                if ($fixed !== null && $configuration['content_type'] === 'table') {
                    $configuration = [
                        'content_type' => 'fixed',
                        'document' => TemplateDocumentDefaults::{self::FIXED_TABLES[$fixed->clave_definicion_campo]}(),
                        'detached_fields' => $configuration['detached_fields'] ?? [],
                    ];
                    foreach ($blockFields as $field) {
                        $configuration['detached_fields'][$field->clave_definicion_campo] ??= [
                            'editable_docente' => (bool) $field->editable_docente,
                            'ia_habilitada' => (bool) $field->ia_habilitada,
                        ];
                        $detach[] = $field->id;
                    }
                }

                // Solo se conserva lo que se restaura al volver a usar un campo retirado.
                if (is_array($configuration['detached_fields'] ?? null)) {
                    $configuration['detached_fields'] = array_map(
                        fn (mixed $saved): array => array_intersect_key(is_array($saved) ? $saved : [], array_flip(['editable_docente', 'ia_habilitada'])),
                        $configuration['detached_fields'],
                    );
                }
                if (is_array($configuration['table'] ?? null)) {
                    $configuration['table'] = TableLayout::normalize($configuration['table']);
                }

                $block['configuracion'] = $configuration;
                $document['secciones'][$s]['bloques'][$b] = $block;
            }
        }

        DB::table('plantilla_silabo')->where('id', $templateId)->update([
            'documento_plantilla' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        DB::table('definiciones_campo')->whereIn('id', $detach)->update(['editable_docente' => false, 'ia_habilitada' => false]);
        if ($flowFieldIds !== []) {
            // El estado de revisión era un valor del sistema, no trabajo del docente.
            DB::table('resultados_validacion')->whereIn('definicion_campo_id', $flowFieldIds)->update(['definicion_campo_id' => null]);
            DB::table('valores_campo')->whereIn('definicion_campo_id', $flowFieldIds)->delete();
            DB::table('filas_repetibles')->whereIn('definicion_campo_id', $flowFieldIds)->delete();
            DB::table('definiciones_campo')->whereIn('id', $flowFieldIds)->delete();
        }
    }
};
