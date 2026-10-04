<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Application\FieldOptions;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Application\Actions\CopySyllabusContent;
use App\Modules\Syllabus\Application\Actions\StartDraft;
use App\Modules\Syllabus\Application\Actions\SubmitSyllabus;
use App\Modules\Syllabus\Application\Actions\UpdateDraftField;
use App\Modules\Syllabus\Application\Actions\ValidateDraft;
use App\Modules\Syllabus\Application\IdentificationCard;
use App\Modules\Syllabus\Application\ReviewComments;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Modules\Syllabus\Presentation\Http\Requests\CopySyllabusContentRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\SubmitSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\UpdateDraftFieldRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ViewSyllabiRequest;
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
            ->when($search, fn ($query, string $term) => $query->where(
                fn ($outer) => $outer
                    ->whereRaw("datos_academicos_silabo->'subject'->>'name' ILIKE ?", ["%{$term}%"])
                    ->orWhereRaw("datos_academicos_silabo->'subject'->>'code' ILIKE ?", ["%{$term}%"])
                    ->orWhereHas('convocation.process.academicPeriod', fn ($period) => $period
                        ->whereRaw('codigo_periodo_academico ILIKE ?', ["%{$term}%"])),
            ))
            ->when($state, fn ($query, string $value) => $query->where('estado', $value))
            ->with(['convocation:id,carrera_id,convocatoria_universidad_id', 'convocation.career:id,nombre', 'convocation.process:id,periodo_academico_id', 'convocation.process.academicPeriod:id,codigo', 'parallel.scheduledSubject.subject:id,nombre,codigo_asignatura', 'parallel:id,codigo'])
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
        ]);
    }

    public function copyFrom(
        Syllabus $syllabus,
        Syllabus $source,
        CopySyllabusContentRequest $request,
        CopySyllabusContent $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $updated = $action->execute($syllabus, $source, $request->integer('version_bloqueo'), $actor, $request);

        return response()->json([
            'message' => "Contenido copiado desde el paralelo {$source->parallel->codigo}.",
            'syllabus' => $this->syllabusPayload($updated),
        ]);
    }

    public function validateDraft(Syllabus $syllabus, Request $request, ValidateDraft $action): JsonResponse
    {
        abort_unless($request->user()?->can('edit', $syllabus) === true, 403);
        $actor = $request->user();
        $validation = $action->execute($syllabus, $actor, $request);

        $message = $validation->blockingErrors() === 0
            ? 'Validación completada sin errores bloqueantes.'
            : 'Validación completada. Revise los campos señalados.';

        return response()->json([
            'message' => $message,
            'validation' => $validation->toArray(),
        ]);
    }

    /** @return array<string, mixed> */
    private function syllabusPayload(Syllabus $syllabus): array
    {
        $syllabus->load([
            'convocation.career', 'convocation.process.academicPeriod', 'parallel.scheduledSubject.subject', 'parallel', 'teachers:id,nombre,correo_electronico',
            'values',
            'revisions' => fn ($query) => $query->with([
                'submitter:id,nombre', 'approval.approver:id,nombre',
            ]),
        ]);
        $values = $syllabus->values->keyBy('definicion_campo_id');
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
            'copy_sources' => $this->copySources($syllabus),
            'teachers' => $syllabus->teachers->pluck('nombre')->unique()->values(),
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
            'template_variables' => TemplateVariables::resolve(['identification' => IdentificationCard::fromSyllabus($syllabus)]),
            'sections' => $template->sections
                ->map(fn (TemplateSection $section): array => $this->sectionPayload($section, $values))
                ->values(),
            'revisions' => $syllabus->revisions->sortBy('numero_revision')->map(fn (SyllabusRevision $revision): array => [
                'id' => $revision->id,
                'number' => $revision->numero_revision,
                'submitted_at' => $revision->enviado_en->toIso8601String(),
                'submitted_by' => $revision->submitter->nombre,
                'approved_at' => $revision->approval?->aprobado_en->toIso8601String(),
            ])->values(),
            'review_stage' => ReviewStage::of($syllabus),
            'review_comments' => ReviewComments::forSyllabus($syllabus),
        ];
    }

    /** @return list<array{id: string, parallel: string, state: string}> */
    private function copySources(Syllabus $syllabus): array
    {
        $user = request()->user();
        $ability = $syllabus->estado === 'sin_iniciar' ? 'start' : 'edit';
        if (! $user instanceof User || ! Gate::forUser($user)->allows($ability, $syllabus)) {
            return [];
        }

        return array_values(CopySyllabusContent::sources($syllabus, $user)
            ->map(fn (Syllabus $source): array => [
                'id' => $source->id,
                'parallel' => $source->parallel->codigo,
                'state' => $source->estado,
            ])->all());
    }

    /** @return array{editable: bool, edit_block_reason: string|null} */
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

    /**
     * @param  Collection<string, FieldValue>  $values
     * @return array<string, mixed>
     */
    private function sectionPayload(TemplateSection $section, $values): array
    {
        $blocks = [];
        foreach ($section->blocks as $block) {
            $blocks[] = $this->blockPayload($block, $values);
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
     * @return array<string, mixed>
     */
    private function blockPayload(TemplateBlock $block, Collection $values): array
    {
        $fields = [];
        foreach ($block->fields as $field) {
            if (isset($block->configuracion['detached_fields'][$field->clave])) {
                continue;
            }
            $fields[] = [
                'id' => $field->id,
                'key' => $field->clave,
                'label' => $field->etiqueta,
                'help' => $field->ayuda,
                'type' => $field->tipo,
                'options' => FieldOptions::options($field),
                'show_when' => FieldConditions::normalize($field->mostrar_si),
                'required' => true,
                'inherited' => $field->heredado,
                'teacher_editable' => $field->editable_docente,
                'value' => $values->get($field->id)?->valor,
            ];
        }

        return [
            'id' => $block->id,
            'title' => $block->titulo,
            'content_type' => $block->contentType(),
            'page_orientation' => $block->pageOrientation(),
            'document' => $block->configuracion['document'] ?? null,
            'fields' => $fields,
        ];
    }
}
