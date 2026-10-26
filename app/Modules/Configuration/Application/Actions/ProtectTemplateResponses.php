<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ProtectTemplateResponses
{
    public function __construct(private ProcessLocks $locks, private RecordAuditEvent $audit) {}

    /** @return array{updated: bool, fields: int, preserved_values: int} */
    public function execute(SyllabusTemplate $template): array
    {
        return DB::transaction(function () use ($template): array {
            SyllabusProcess::query()->inProgress()->lockForUpdate()->get();
            $this->locks->assertTemplateEditable();
            DB::statement('LOCK TABLE silabos IN SHARE MODE');
            if (Syllabus::withoutGlobalScopes()->exists()) {
                throw ValidationException::withMessages(['template' => 'Esta actualización inicial requiere que no existan sílabos. Use el flujo administrativo con pausa y confirmación para una convocatoria con trabajo existente.']);
            }
            $locked = SyllabusTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $fields = FieldDefinition::query()->where('plantilla_id', $locked->id)->get()->keyBy('clave');
            $units = $fields->get('unidades');
            if (! $units instanceof FieldDefinition || $units->block === null) {
                throw ValidationException::withMessages(['template' => 'La plantilla no tiene el campo de unidades esperado. Adapte sus campos desde el editor administrativo.']);
            }
            $preserved = FieldValue::query()->count();
            $official = OfficialTemplate::definition();
            $current = $locked->definition();
            $kind = $units->contenido['content'][0]['attrs']['kind'] ?? null;
            if (($current->toArray()['response_policy'] ?? null) === 'protected' && $fields->has('unidades_curriculares')
                && $kind === 'unidades' && ($current->blocks()['unidades'] ?? null) === $official->blocks()['unidades']) {
                return ['updated' => false, 'fields' => $fields->count(), 'preserved_values' => $preserved];
            }
            $officialFields = collect(OfficialTemplate::sections())->flatMap(fn (array $section): array => $section['fields']);
            $catalog = $officialFields->firstWhere('key', 'unidades_curriculares');
            $planning = $officialFields->firstWhere('key', 'unidades');
            if (! is_array($catalog) || ! is_array($planning)) {
                throw ValidationException::withMessages(['template' => 'La plantilla oficial no contiene los campos de unidades esperados.']);
            }
            // Desde ADR-0012 inicial: catálogo ya creado y planificación en el bloque retirado.
            $split = $fields->has('unidades_curriculares') && $kind === 'planificacion_semanal';
            if ($fields->has('unidades_curriculares') && ! $split) {
                throw ValidationException::withMessages(['template' => 'Ya existe un campo de unidades curriculares. Revise su contenido antes de actualizar la plantilla.']);
            }
            if (! $split && array_any($current->presets(), fn (array $preset): bool => $preset['key'] === 'unidades_curriculares')) {
                throw ValidationException::withMessages(['template' => 'Ya existe una plantilla lista con la clave del catálogo de unidades. Revísela desde el editor administrativo.']);
            }
            foreach (['horas_docencia', 'horas_practicas', 'horas_autonomas'] as $variable) {
                if (($current->variables()[$variable]['source'] ?? null) !== $official->variables()[$variable]['source']) {
                    throw ValidationException::withMessages(['template' => 'La plantilla tiene un catálogo de horas personalizado. Adapte sus reglas desde el editor administrativo.']);
                }
            }
            $presets = array_map(fn (array $preset): array => $preset['field'] === 'unidades'
                ? [...$preset, 'content' => $planning['content'], 'quien_llena' => FieldDefinition::TEACHER] : $preset, $current->presets());
            if (! $split) {
                $presets[] = collect($official->presets())->firstWhere('field', 'unidades_curriculares');
            }
            $blocks = $current->blocks();
            unset($blocks['planificacion_semanal']);
            $definition = TemplateDefinition::trusted([
                ...$current->toArray(),
                'response_policy' => 'protected',
                'blocks' => [...$blocks, 'unidades' => $official->blocks()['unidades']],
                'presets' => $presets,
            ]);
            // El contenido oficial viene de HTML: se normaliza para que el bloque tenga sus cuatro unidades.
            [$catalogContent, $planningContent] = app(TemplateContext::class)->using($definition, fn (): array => [
                FieldDocument::normalize($catalog['content']),
                FieldDocument::normalize($planning['content']),
            ]);
            app(TemplateContext::class)->using($definition, function () use ($fields): void {
                foreach (FieldValue::query()->whereNotNull('asignatura_id')->whereIn('definicion_campo_id', $fields->pluck('id'))->get() as $value) {
                    $field = $fields->firstWhere('id', $value->definicion_campo_id);
                    FieldResponse::normalize($field->contenido, $value->valor);
                }
            });
            if (! $split) {
                $sectionId = $units->block->seccion_plantilla_id;
                $ids = $locked->sectionOrFail($sectionId)->blocks()->sortBy('posicion')->map(fn (TemplateBlock $block): string => $block->id)->values()->all();
                $blockId = (string) Str::uuid();
                $locked->appendBlock($sectionId, ['id' => $blockId, 'clave' => 'unidades_curriculares', 'titulo' => $catalog['label'] ?? 'Unidades y resultados de aprendizaje', 'posicion' => count($ids) + 1]);
                FieldDefinition::query()->create([
                    'plantilla_id' => $locked->id,
                    'campo_plantilla_id' => $blockId,
                    'quien_llena' => FieldDefinition::COORDINATION,
                    'contenido' => $catalogContent,
                ]);
                $position = array_search($units->campo_plantilla_id, $ids, true);
                array_splice($ids, $position === false ? count($ids) : $position, 0, [$blockId]);
                $locked->reorderBlocks($sectionId, $ids);
            }
            $units->update(['quien_llena' => FieldDefinition::TEACHER, 'contenido' => $planningContent]);
            $locked->updateBlockDocument($units->campo_plantilla_id, ['titulo' => $planning['label'] ?? 'Unidades']);
            $locked->saveDefinition($definition);
            $this->audit->execute(
                actorId: null,
                roleAssignmentId: null,
                action: 'plantilla.respuestas_protegidas_activadas',
                resourceType: 'plantilla_silabo',
                resourceId: $locked->id,
                result: 'exito',
                metadata: ['origen' => 'mantenimiento_cli', 'silabos' => 0, 'valores_preservados' => $preserved],
            );

            return ['updated' => true, 'fields' => $fields->count() + ($split ? 0 : 1), 'preserved_values' => $preserved];
        });
    }
}
