<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\CurricularFieldOptions;
use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\TableLayout;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Application\AcademicContextValues;
use App\Modules\Syllabus\Application\Actions\StartDraft;
use App\Modules\Syllabus\Application\Actions\SubmitSyllabus;
use App\Modules\Syllabus\Application\Actions\UpdateDraftField;
use App\Modules\Syllabus\Application\Actions\ValidateDraft;
use App\Modules\Syllabus\Application\IdentificationCard;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\RepeatableRow;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\ValidationRun;
use App\Modules\Syllabus\Presentation\Http\Requests\SubmitSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\UpdateDraftFieldRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ViewSyllabiRequest;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class SyllabusController extends Controller
{
    public function __construct(
        private readonly InstitutionalLogos $logos,
    ) {}

    public function index(ViewSyllabiRequest $request, ActiveRole $roles): JsonResponse
    {
        $activeRole = $roles->resolve($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $filters = $request->validated();
        $search = is_string($filters['q'] ?? null) ? trim($filters['q']) : null;
        $state = in_array($filters['state'] ?? null, [
            'sin_iniciar', 'borrador', 'en_revision', 'correccion_solicitada', 'aprobado',
        ], true) ? $filters['state'] : null;

        $syllabi = Syllabus::query()
            ->whereHas('convocation', fn ($query) => $query->where('carrera_id', $activeRole?->carrera_id))
            ->whereCurrentTeacher($user)
                // La búsqueda mira la asignatura y la convocatoria, que es como se
                // recuerda un expediente: por la materia y por el periodo en que se pidió.
            ->when($search, fn ($query, string $term) => $query->where(
                fn ($outer) => $outer
                    ->whereRaw("contexto_academico_silabo->'subject'->>'name' ILIKE ?", ["%{$term}%"])
                    ->orWhereRaw("contexto_academico_silabo->'subject'->>'code' ILIKE ?", ["%{$term}%"])
                    ->orWhereHas('convocation.process.academicPeriod', fn ($period) => $period
                        ->whereRaw('codigo_periodo_academico ILIKE ?', ["%{$term}%"])),
            ))
            ->when($state, fn ($query, string $value) => $query->where('estado', $value))
            ->with(['convocation:id,carrera_id,proceso_id', 'convocation.career:id,nombre', 'convocation.process:id,periodo_academico_id', 'convocation.process.academicPeriod:id,codigo', 'subject:id,nombre,codigo_asignatura', 'parallel:id,codigo'])
            ->orderByRaw('guardado_en DESC NULLS LAST')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Syllabus $syllabus) => [
                'id' => $syllabus->id,
                'subject' => $syllabus->academicSubjectName(),
                'code' => $syllabus->academicSubjectCode(),
                'convocation' => $syllabus->convocation->nombre,
                'period' => $syllabus->convocation->process->academicPeriod->codigo,
                'state' => $syllabus->estado,
                'iniciado_en' => $syllabus->iniciado_en?->toIso8601String(),
                'parallels' => [$syllabus->parallel->codigo],
                'guardado_en' => $syllabus->guardado_en?->toIso8601String(),
            ]);

        $payload = [
            'filters' => ['q' => $search ?: null, 'state' => $state],
            'syllabi' => $syllabi,
        ];

        return response()->json($payload);
    }

    public function show(Syllabus $syllabus, Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('view', $syllabus) === true, 403);

        $payload = ['syllabus' => $this->syllabusPayload($syllabus)];

        return response()->json($payload);
    }

    public function start(Syllabus $syllabus, Request $request, StartDraft $action): JsonResponse
    {
        abort_unless($request->user()?->can('start', $syllabus) === true, 403);
        $actor = $request->user();
        $started = $action->execute($syllabus->id, $actor, $request);

        return response()->json([
            'message' => 'Borrador iniciado con la plantilla y los datos maestros fijados.',
            'syllabus' => $this->syllabusPayload($started),
        ]);
    }

    public function submit(
        Syllabus $syllabus,
        SubmitSyllabusRequest $request,
        SubmitSyllabus $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $revision = $action->execute(
            $syllabus,
            $request->integer('version_bloqueo'),
            $request->string('idempotency_key')->toString(),
            $actor,
            $request,
        );

        return response()->json([
            'message' => "Revisión {$revision->numero_revision} enviada y fijada correctamente.",
            'syllabus' => $this->syllabusPayload($syllabus->fresh() ?? $syllabus),
        ]);
    }

    public function updateField(
        Syllabus $syllabus,
        FieldDefinition $field,
        UpdateDraftFieldRequest $request,
        UpdateDraftField $action,
    ): JsonResponse {
        $actor = $request->user();
        $updated = $action->execute($syllabus, $field, $request->draftData(), $actor, $request);

        return response()->json([
            'message' => 'Borrador guardado.',
            'version_bloqueo' => $updated->version_bloqueo,
            'guardado_en' => $updated->guardado_en?->toIso8601String(),
            // Misma forma que la carga del borrador (`id`, `data`, `position`): el
            // editor reemplaza sus filas con esta respuesta y otra forma las borraba.
            'rows' => $field->tipo === 'repetible'
                ? $updated->rows()->where('definicion_campo_id', $field->id)->orderBy('posicion')->get(['id', 'datos', 'posicion'])
                    ->map(fn (RepeatableRow $row): array => ['id' => $row->id, 'data' => $row->datos, 'position' => $row->posicion])
                    ->values()
                : [],
        ]);
    }

    public function validateDraft(Syllabus $syllabus, Request $request, ValidateDraft $action): JsonResponse
    {
        abort_unless($request->user()?->can('edit', $syllabus) === true, 403);
        $actor = $request->user();
        $run = $action->execute($syllabus, $actor, $request);

        $message = $run->errores_bloqueantes === 0
            ? 'Validación completada sin errores bloqueantes.'
            : 'Validación completada. Revise las partes señaladas.';

        return response()->json([
            'message' => $message,
            'validation' => [
                'version_bloqueo' => $run->version_bloqueo,
                'blocking_errors' => $run->errores_bloqueantes,
                'warnings' => $run->advertencias,
                'results' => $run->results->map(fn ($result): array => [
                    'field_id' => $result->definicion_campo_id,
                    'code' => $result->codigo,
                    'severity' => $result->severidad,
                    'message' => $result->mensaje,
                ])->values(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function syllabusPayload(Syllabus $syllabus): array
    {
        $syllabus->load([
            'convocation.career', 'convocation.process.academicPeriod', 'subject', 'parallel', 'teachers:id,nombre,correo_electronico',
            'values', 'rows',
            'validationRuns' => fn ($query) => $query->with('results')->latest('completado_en')->limit(1),
            'revisions' => fn ($query) => $query->with([
                'submitter:id,nombre', 'approval.approver:id,nombre',
                'correctionRequest', 'observations',
            ]),
            'reopenings' => fn ($query) => $query->with('reopener:id,nombre')->latest('reabierto_en'),
        ]);
        $values = $syllabus->values->keyBy('definicion_campo_id');
        $rows = $syllabus->rows->groupBy('definicion_campo_id');
        $lastValidation = $syllabus->validationRuns->first();
        $template = SyllabusTemplate::institutional();

        return [
            'id' => $syllabus->id,
            'subject' => $syllabus->academicSubjectName(),
            'code' => $syllabus->academicSubjectCode(),
            'convocation' => $syllabus->convocation->nombre,
            'period' => $syllabus->convocation->process->academicPeriod->codigo,
            'state' => $syllabus->estado,
            'iniciado_en' => $syllabus->iniciado_en?->toIso8601String(),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'guardado_en' => $syllabus->guardado_en?->toIso8601String(),
            ...$this->teacherEditability($syllabus),
            'parallels' => [$syllabus->parallel->codigo],
            'teachers' => $syllabus->teachers->pluck('nombre')->unique()->values(),
            // I-139: revisión aprobada vigente, la que el docente firma electrónicamente.
            'approved_revision_id' => $syllabus->estado === 'aprobado'
                ? $syllabus->revisions()->whereHas('approval')->latest('numero_revision')->value('id')
                : null,
            'presentation' => [
                'appearance' => TemplateAppearance::fromMapping($template->mapeo_documento),
                'title' => TemplateTitleBlock::fromMapping($template->mapeo_documento)['text'],
                'logos' => [
                    'institution' => route('logos.institution', ['v' => $this->logos->version($this->logos->institutionPath())]),
                    'faculty' => asset('images/silabo/facultad.jpeg').'?v='.$this->logos->version(public_path('images/silabo/facultad.jpeg')),
                ],
            ],
            'identification' => IdentificationCard::grid(IdentificationCard::fromSyllabus($syllabus)),
            'template_variables' => TemplateVariables::resolve(['identification' => IdentificationCard::fromSyllabus($syllabus), 'academic_context' => $syllabus->contexto_academico]),
            'planning_expectations' => [
                'teaching_weeks' => AcademicContextValues::scheduledSubject(
                    $syllabus->contexto_academico ?? [],
                    'teaching_weeks',
                ) ?? $syllabus->convocation->process->academicPeriod->semanas_lectivas,
                'credits' => data_get($syllabus->contexto_academico, 'subject.credits'),
                'total_hours' => data_get($syllabus->contexto_academico, 'subject.total_hours'),
                'hours_acd' => data_get($syllabus->contexto_academico, 'subject.hours_ac'),
                'hours_ape' => data_get($syllabus->contexto_academico, 'subject.hours_pae'),
                'hours_aa' => data_get($syllabus->contexto_academico, 'subject.hours_aa'),
            ],
            'sections' => $template->sections
                ->map(fn (TemplateSection $section): array => $this->sectionPayload($section, $values, $rows, $syllabus->contexto_academico ?? []))
                ->values(),
            'validation' => $lastValidation instanceof ValidationRun ? [
                'completed_at' => $lastValidation->completado_en->toIso8601String(),
                'version_bloqueo' => $lastValidation->version_bloqueo,
                'blocking_errors' => $lastValidation->errores_bloqueantes,
                'warnings' => $lastValidation->advertencias,
                'results' => $lastValidation->results->map(fn ($result) => [
                    'field_id' => $result->definicion_campo_id,
                    'code' => $result->codigo,
                    'severity' => $result->severidad,
                    'message' => $result->mensaje,
                ])->values(),
            ] : null,
            'revisions' => $syllabus->revisions->sortBy('numero_revision')->map(fn (SyllabusRevision $revision): array => [
                'id' => $revision->id,
                'number' => $revision->numero_revision,
                'submitted_at' => $revision->enviado_en->toIso8601String(),
                'submitted_by' => $revision->submitter->nombre,
                'approved_at' => $revision->approval?->aprobado_en->toIso8601String(),
            ])->values(),
            'observations' => $this->teacherObservationsPayload($syllabus),
            'reopening' => $syllabus->reopenings->first() === null ? null : [
                'cause' => $syllabus->reopenings->first()->causa,
                'reopened_at' => $syllabus->reopenings->first()->reabierto_en->toIso8601String(),
                'reopened_by' => $syllabus->reopenings->first()->reopener->nombre,
            ],
        ];
    }

    /**
     * Indica al editor docente si puede escribir ahora y, si no, por qué. Reutiliza la
     * política (`start`/`edit`) para no duplicar la regla; el motivo solo explica.
     *
     * @return array{editable: bool, edit_block_reason: string|null}
     */
    private function teacherEditability(Syllabus $syllabus): array
    {
        $user = request()->user();
        $ability = $syllabus->estado === 'sin_iniciar' ? 'start' : 'edit';
        if ($user instanceof User && Gate::forUser($user)->allows($ability, $syllabus)) {
            return ['editable' => true, 'edit_block_reason' => null];
        }

        $convocationState = $syllabus->convocation->estado;
        $reason = match (true) {
            ! in_array($syllabus->estado, ['sin_iniciar', 'borrador', 'correccion_solicitada'], true) => 'El sílabo no admite cambios en su estado actual.',
            $convocationState === Convocation::STATE_CLOSED => 'La convocatoria está cerrada: el sílabo queda solo para consulta.',
            ! $syllabus->convocation->isRunning() => 'La convocatoria está en pausa. Podrá seguir editando cuando se reanude.',
            default => 'No tiene una asignación vigente para editar este sílabo.',
        };

        return ['editable' => false, 'edit_block_reason' => $reason];
    }

    /** @return list<array<string, mixed>> */
    private function teacherObservationsPayload(Syllabus $syllabus): array
    {
        $payload = [];
        foreach ($syllabus->revisions->sortBy('numero_revision') as $revision) {
            // Una solicitud de corrección incluye todas las observaciones de su revisión.
            $requested = $revision->correctionRequest !== null;
            foreach ($revision->observations as $observation) {
                $payload[] = [
                    'id' => $observation->id,
                    'revision_number' => $revision->numero_revision,
                    'section_key' => $observation->clave_seccion,
                    'field_key' => $observation->clave_campo,
                    'content' => $observation->contenido,
                    'state' => $observation->estado,
                    'requested' => $requested,
                ];
            }
        }

        return $payload;
    }

    /**
     * @param  Collection<string, FieldValue>  $values
     * @param  Collection<string, EloquentCollection<int, RepeatableRow>>  $rows
     * @param  array<string, mixed>  $academicContext
     * @return array<string, mixed>
     */
    private function sectionPayload(TemplateSection $section, $values, $rows, array $academicContext): array
    {
        $blocks = [];
        foreach ($section->blocks as $block) {
            $blocks[] = $this->blockPayload($block, $values, $rows, $academicContext);
        }

        return [
            'id' => $section->id,
            'key' => $section->clave,
            'title' => $section->titulo,
            'description' => $section->descripcion,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  Collection<string, FieldValue>  $values
     * @param  Collection<string, EloquentCollection<int, RepeatableRow>>  $rows
     * @param  array<string, mixed>  $academicContext
     * @return array<string, mixed>
     */
    private function blockPayload(TemplateBlock $block, Collection $values, Collection $rows, array $academicContext): array
    {
        $fields = [];
        foreach ($block->fields as $field) {
            // Un campo retirado del diseño ya no es parte del formulario (I-116).
            if (isset($block->configuracion['detached_fields'][$field->clave])) {
                continue;
            }
            $fieldRows = [];
            foreach ($rows->get($field->id) ?? [] as $row) {
                $fieldRows[] = [
                    'id' => $row->id,
                    'data' => $row->datos,
                    'position' => $row->posicion,
                ];
            }
            $fields[] = [
                'id' => $field->id,
                'key' => $field->clave,
                'label' => $field->etiqueta,
                'help' => $field->ayuda,
                'type' => $field->tipo,
                'options' => CurricularFieldOptions::options($field, $academicContext),
                'options_source' => CurricularFieldOptions::source($field),
                'options_sources' => CurricularFieldOptions::sources($field),
                // «Mostrar solo si»: la hoja oculta la parte mientras no se cumple.
                'show_when' => FieldConditions::normalize($field->mostrar_si),
                'required' => true,
                'inherited' => $field->heredado,
                'teacher_editable' => $field->editable_docente,
                'ai_enabled' => $field->ia_habilitada
                    && in_array($field->tipo, ['texto_corto', 'texto_largo'], true),
                'value' => $values->get($field->id)?->valor,
                'rows' => $fieldRows,
            ];
        }

        return [
            'id' => $block->id,
            'title' => $block->titulo,
            'content_type' => $block->contentType(),
            'table' => TableLayout::fromBlock($block),
            'page_orientation' => $block->pageOrientation(),
            'document' => $block->configuracion['document'] ?? null,
            'fields' => $fields,
        ];
    }
}
