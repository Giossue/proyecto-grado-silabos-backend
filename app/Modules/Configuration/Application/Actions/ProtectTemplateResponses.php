<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Support\Facades\DB;
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
            $catalog = $fields->get('unidades_curriculares');
            if (($current->toArray()['response_policy'] ?? null) === 'protected' && $catalog === null
                && $kind === 'unidades' && ($current->blocks()['unidades'] ?? null) === $official->blocks()['unidades']) {
                return ['updated' => false, 'fields' => $fields->count(), 'preserved_values' => $preserved];
            }
            $planning = collect(OfficialTemplate::sections())->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'unidades');
            if (! is_array($planning)) {
                throw ValidationException::withMessages(['template' => 'La plantilla oficial no contiene el campo de unidades esperado.']);
            }
            foreach (['horas_docencia', 'horas_practicas', 'horas_autonomas'] as $variable) {
                if (($current->variables()[$variable]['source'] ?? null) !== $official->variables()[$variable]['source']) {
                    throw ValidationException::withMessages(['template' => 'La plantilla tiene un catálogo de horas personalizado. Adapte sus reglas desde el editor administrativo.']);
                }
            }
            $presets = array_values(array_filter(array_map(fn (array $preset): array => $preset['field'] === 'unidades'
                ? [...$preset, 'content' => $planning['content'], 'quien_llena' => FieldDefinition::CAREER] : $preset, $current->presets()),
                fn (array $preset): bool => $preset['key'] !== 'unidades_curriculares' && $preset['field'] !== 'unidades_curriculares'));
            $blocks = $current->blocks();
            unset($blocks['planificacion_semanal']);
            $definition = TemplateDefinition::trusted([
                ...$current->toArray(),
                'response_policy' => 'protected',
                'blocks' => [...$blocks, 'unidades' => $official->blocks()['unidades']],
                'presets' => $presets,
            ]);
            // El contenido oficial viene de HTML: se normaliza para que el bloque tenga sus cuatro unidades.
            $planningContent = app(TemplateContext::class)->using($definition, fn (): array => FieldDocument::normalize($planning['content']));
            $profileFields = $fields->except(['unidades_curriculares']);
            app(TemplateContext::class)->using($definition, function () use ($profileFields): void {
                foreach (FieldValue::query()->whereNotNull('asignatura_id')->whereIn('definicion_campo_id', $profileFields->pluck('id'))->get() as $value) {
                    $field = $profileFields->firstWhere('id', $value->definicion_campo_id);
                    FieldResponse::normalize($field->contenido, $value->valor);
                }
            });
            if ($catalog instanceof FieldDefinition) {
                $locked->removeBlockDocument($catalog->campo_plantilla_id);
            }
            $units->update(['quien_llena' => FieldDefinition::CAREER, 'contenido' => $planningContent]);
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

            return ['updated' => true, 'fields' => $fields->count() - ($catalog === null ? 0 : 1), 'preserved_values' => $preserved];
        });
    }
}
