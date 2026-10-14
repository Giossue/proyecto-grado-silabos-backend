<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\FieldDocument;
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
use App\Modules\Syllabus\Application\SnapshotDocuments;
use App\Modules\Syllabus\Application\SyllabusDocumentFields;
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
            ->inCurrentPeriod()
            ->when($search, fn ($query, string $term) => $query
                ->whereRaw("datos_academicos_silabo->'subject'->>'name' ILIKE ?", ['%'.addcslashes($term, '%_\\').'%']))
            ->when($state, fn ($query, string $value) => $query->where('estado', $value))
            ->with(['convocation:id,carrera_id,convocatoria_universidad_id', 'convocation.career:id,nombre', 'convocation.process:id,periodo_academico_id', 'convocation.process.academicPeriod:id,codigo', 'parallel.subject'])
            ->orderByRaw('guardado_en DESC NULLS LAST')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Syllabus $syllabus) => [
                'id' => $syllabus->id,
                'subject' => $syllabus->academicSubjectName(),
                'code' => $syllabus->academicSubjectCode(),
                'convocation' => $syllabus->convocation->nombre,
                'period' => $syllabus->convocation->process->academicPeriod->codigo,
                'state' => $syllabus->estado,
                'iniciado_en' => $syllabus->iniciado_en?->toIso8601String(),
                'parallels' => [$syllabus->academicParallelCode()],
                'guardado_en' => $syllabus->guardado_en?->toIso8601String(),
            ]);

        $payload = [
            'filters' => ['q' => $search ?: null, 'state' => $state],
            'syllabi' => $syllabi,
        ];

        return response()->json($payload);
    }

    public function history(ViewSyllabiRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $careers = Syllabus::query()
            ->whereCurrentTeacher($user)
            ->inPastPeriod()
            ->with(['convocation:id,carrera_id', 'convocation.career:id,nombre'])
            ->get()
            ->groupBy(fn (Syllabus $syllabus): string => $syllabus->convocation->carrera_id)
            ->map(fn (Collection $group, string $careerId): array => [
                'id' => $careerId,
                'name' => $group->first()->convocation->career->nombre,
                'syllabi_count' => $group->count(),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json(['careers' => $careers]);
    }

    public function historyCareer(string $career, ViewSyllabiRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $syllabi = Syllabus::query()
            ->whereCurrentTeacher($user)
            ->inPastPeriod()
            ->whereHas('convocation', fn ($query) => $query->where('carrera_id', $career))
            ->with(['convocation:id,carrera_id,convocatoria_universidad_id', 'convocation.career:id,nombre', 'convocation.process:id,periodo_academico_id', 'convocation.process.academicPeriod:id,codigo,fecha_fin', 'parallel.subject'])
            ->get()
            ->sortByDesc(fn (Syllabus $syllabus) => $syllabus->convocation->process->academicPeriod->fecha_fin)
            ->values();
        abort_if($syllabi->isEmpty(), 404);

        return response()->json([
            'career' => ['id' => $career, 'name' => $syllabi->first()->convocation->career->nombre],
            'syllabi' => $syllabi->map(fn (Syllabus $syllabus): array => [
                'id' => $syllabus->id,
                'subject' => $syllabus->academicSubjectName(),
                'period' => $syllabus->convocation->process->academicPeriod->codigo,
                'state' => $syllabus->estado,
                'parallels' => [$syllabus->academicParallelCode()],
                'guardado_en' => $syllabus->guardado_en?->toIso8601String(),
            ])->values(),
        ]);
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
            'message' => "Contenido copiado desde el paralelo {$source->academicParallelCode()}.",
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

    /**
     * @return array<string, mixed>
     */
    private function syllabusPayload(Syllabus $syllabus): array
    {
        $syllabus->load([
            'convocation.career', 'convocation.process.academicPeriod', 'parallel.subject', 'parallel', 'teachers:id,nombre,correo_electronico',
            'values',
            'revisions' => fn ($query) => $query->with([
                'submitter:id,nombre', 'approval.approver:id,nombre',
            ]),
        ]);
        $values = $syllabus->values->keyBy('definicion_campo_id');
        $template = SyllabusTemplate::institutional();
        $identification = IdentificationCard::fromSyllabus($syllabus);
        $revision = $syllabus->revisions->sortByDesc('numero_revision')->first();
        $frozen = $revision instanceof SyllabusRevision && in_array($syllabus->estado, ['en_revision', 'aprobado'], true)
            ? SnapshotDocuments::present($revision->fotografia) : null;
        $archived = $frozen === null ? SyllabusDocumentFields::archivedSections($syllabus) : null;
        $archivedView = $archived === null ? null : SnapshotDocuments::present(['sections' => $archived, 'identification' => $identification]);
        if ($archivedView !== null) {
            foreach ($archivedView['sections'] as &$section) {
                foreach ($section['fields'] as &$field) {
                    $field['teacher_editable'] = $field['quien_llena'] === 'docente';
                    $field['required'] = $field['quien_llena'] !== 'institucional';
                }
            }
        }
        $mapping = $frozen['document_mapping'] ?? $template->mapeo_documento;
        $variables = $frozen['template_variables'] ?? TemplateVariables::resolve(['identification' => $identification]);

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
            'parallels' => [$syllabus->academicParallelCode()],
            'copy_sources' => $this->copySources($syllabus),
            'teachers' => $syllabus->teachers->pluck('nombre')->unique()->values(),
            'approved_revision_id' => $syllabus->estado === 'aprobado'
                ? $syllabus->revisions()->whereHas('approval')->latest('numero_revision')->value('id')
                : null,
            'presentation' => [
                'appearance' => TemplateAppearance::fromMapping($mapping),
                'title' => TemplateTitleBlock::fromMapping($mapping)['text'],
                'logos' => [
                    'institution' => route('logos.institution', ['v' => $this->logos->version($this->logos->institutionPath())]),
                    'faculty' => asset('images/silabo/facultad.jpeg').'?v='.$this->logos->version(public_path('images/silabo/facultad.jpeg')),
                ],
            ],
            'template_variables' => $variables,
            'blocks' => [
                'identificacion' => ['table' => DocumentBlock::identificationLayout($variables)],
                'unidades' => ['weeks' => DocumentBlock::WEEKS, 'hours' => ['acd' => DocumentBlock::hours($variables['horas_docencia'] ?? null), 'ape' => DocumentBlock::hours($variables['horas_practicas'] ?? null), 'aa' => DocumentBlock::hours($variables['horas_autonomas'] ?? null)]],
                'evaluacion' => ['with_practice' => ! (isset($variables['horas_practicas']) && trim($variables['horas_practicas']) !== '' && DocumentBlock::hours($variables['horas_practicas']) === 0.0)],
            ],
            'sections' => $frozen['sections'] ?? $archivedView['sections'] ?? $template->sections
                ->map(fn (TemplateSection $section): array => $this->sectionPayload($section, $values, ['identification' => $identification]))
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

    /**
     * @return list<array{id: string, parallel: string, state: string}>
     */
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
                'parallel' => $source->academicParallelCode(),
                'state' => $source->estado,
            ])->all());
    }

    /**
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

    /**
     * @param  Collection<string, FieldValue>  $values
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sectionPayload(TemplateSection $section, $values, array $context): array
    {
        $variables = TemplateVariables::resolve($context);

        return [
            'id' => $section->id,
            'key' => $section->clave,
            'title' => $section->titulo,
            'orientation' => $section->orientacion,
            'fields' => $section->blocks->flatMap(fn (TemplateBlock $block) => $block->fields->map(function (FieldDefinition $field) use ($values, $variables): array {
                $stored = ($values->get($field->id) instanceof FieldValue ? $values->get($field->id)->valor : $field->contenido);
                $document = FieldDocument::normalize($field->quien_llena === FieldDefinition::INSTITUTIONAL ? $field->contenido : $stored);

                return [
                    'id' => $field->id,
                    'key' => $field->clave,
                    'label' => $field->etiqueta,
                    'quien_llena' => $field->quien_llena,
                    'teacher_editable' => $field->teacherEditable(),
                    'required' => $field->quien_llena !== FieldDefinition::INSTITUTIONAL,
                    'value' => $document,
                    'document' => FieldDocument::resolve($document, $variables),
                ];
            })->all())->values()->all(),
        ];
    }
}
