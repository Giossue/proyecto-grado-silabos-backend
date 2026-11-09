<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Application\TemplatePresentation;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Documents\Application\SignedDocumentVariables;
use App\Modules\Documents\Application\SyllabusSignatures;
use App\Modules\Documents\Domain\Data\DecisionSignature;
use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
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
use App\Modules\Syllabus\Application\SyllabusSectionPayload;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Modules\Syllabus\Presentation\Http\Requests\CopySyllabusContentRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\SigningStatementRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\SubmitSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\UpdateDraftFieldRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ViewSyllabiRequest;
use App\Support\Search\AccentInsensitive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class SyllabusController extends Controller
{
    public function __construct(
        private readonly InstitutionalLogos $logos,
        private readonly SyllabusSignatures $signatures,
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
            ->when($search, fn ($query, string $term) => $query->where(
                fn ($outer) => $outer
                    ->whereRaw(AccentInsensitive::matches("datos_academicos_silabo->'subject'->>'name'"), [AccentInsensitive::pattern($term)])
                    ->orWhereRaw(AccentInsensitive::matches("datos_academicos_silabo->'subject'->>'code'"), [AccentInsensitive::pattern($term)]),
            ))
            ->when($state, fn ($query, string $value) => $query->where('estado', $value))
            ->with(['convocation:id,carrera_id,convocatoria_universidad_id', 'convocation.career:id,nombre', 'convocation.process:id,periodo_academico_id', 'convocation.process.academicPeriod:id,codigo', 'parallel.subject'])
            // Por ciclo y luego por asignatura; se usa la foto académica del sílabo y, si falta, la malla.
            ->orderByRaw(<<<'SQL'
                COALESCE((datos_academicos_silabo->'subject'->>'cycle')::int,
                    (SELECT a.ciclo_asignatura FROM paralelos p JOIN asignaturas a ON a.asignatura_id = p.asignatura_id WHERE p.paralelo_id = silabos.paralelo_id)) NULLS LAST
                SQL)
            ->orderByRaw(<<<'SQL'
                COALESCE(datos_academicos_silabo->'subject'->>'name',
                    (SELECT a.nombre_asignatura FROM paralelos p JOIN asignaturas a ON a.asignatura_id = p.asignatura_id WHERE p.paralelo_id = silabos.paralelo_id))
                SQL)
            ->orderBy('silabo_id')
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
            // Sin convocatoria abierta (o en pausa) en la carrera, la lista vacía lo explica.
            'convocation_open' => Convocation::query()
                ->where('carrera_id', $activeRole?->carrera_id)
                ->whereIn('estado', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED])
                ->exists(),
        ];

        return response()->json($payload);
    }

    public function history(ViewSyllabiRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $careers = Syllabus::query()
            ->writtenBy($user)
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
            ->writtenBy($user)
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
            DecisionSignature::from($request->input('signature')),
            $actor,
            $request,
        );

        return response()->json([
            'message' => "Revisión {$revision->numero_revision} enviada.",
            'syllabus' => $this->syllabusPayload($syllabus->fresh() ?? $syllabus),
        ]);
    }

    public function signingStatement(
        Syllabus $syllabus,
        SigningStatementRequest $request,
        SubmitSyllabus $action,
    ): JsonResponse {
        return response()->json($action->statement($syllabus, $request->integer('version_bloqueo')));
    }

    public function updateField(
        Syllabus $syllabus,
        FieldDefinition $field,
        UpdateDraftFieldRequest $request,
        UpdateDraftField $action,
    ): JsonResponse {
        $actor = $request->user();
        $updated = $action->execute($syllabus, $field, $request->draftData(), $actor, $request);
        // La vista previa muestra el campo resuelto (variables y bloques); se devuelve el nuevo.
        $variables = TemplateVariables::resolve(['identification' => IdentificationCard::fromSyllabus($updated)]);

        return response()->json([
            'message' => 'Borrador guardado.',
            'version_bloqueo' => $updated->version_bloqueo,
            'guardado_en' => $updated->guardado_en?->toIso8601String(),
            'document' => FieldDocument::resolve(FieldDocument::normalize($updated->values()->where('definicion_campo_id', $field->id)->first()->valor ?? $field->contenido), $variables),
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
            'convocation.career', 'convocation.process.academicPeriod', 'parallel.subject', 'parallel', 'teachers:id,nombre,correo_electronico,formacion_experiencia_usuario',
            'values',
            'revisions' => fn ($query) => $query->with([
                'submitter:id,nombre', 'approval.approver:id,nombre',
            ]),
        ]);
        $values = $syllabus->values->keyBy('definicion_campo_id');
        $template = SyllabusTemplate::institutional();
        $identification = IdentificationCard::fromSyllabus($syllabus);
        $revision = $syllabus->revisions->sortByDesc('numero_revision')->first();
        $snapshot = $revision instanceof SyllabusRevision && in_array($syllabus->estado, ['en_revision', 'aprobado'], true)
            ? $revision->fotografia : null;
        if ($snapshot !== null) {
            $snapshot['template_variables'] = app(SignedDocumentVariables::class)->of($snapshot, $revision, $revision->approval);
        }
        if ($snapshot === null && $syllabus->convocation->estado === Convocation::STATE_CLOSED
            && is_array($syllabus->contexto_academico['closed_document'] ?? null)) {
            $snapshot = $syllabus->contexto_academico['closed_document'];
        }
        $unsentForm = $snapshot !== null && ! in_array($syllabus->estado, ['en_revision', 'aprobado'], true);
        $frozen = $snapshot === null ? null : SnapshotDocuments::present($snapshot);
        if ($unsentForm && $frozen !== null) {
            $frozen['sections'] = $this->readOnlyForm($frozen['sections']);
        }
        $archived = $frozen === null ? SyllabusDocumentFields::archivedSections($syllabus) : null;
        $archivedView = $archived === null ? null : SnapshotDocuments::present(['sections' => $archived, 'identification' => $identification, 'template_definition' => $template->definition()->toArray()]);
        if ($archivedView !== null) {
            foreach ($archivedView['sections'] as &$section) {
                foreach ($section['fields'] as &$field) {
                    $field['teacher_editable'] = $field['quien_llena'] === 'docente';
                    $field['required'] = $field['quien_llena'] !== 'institucional';
                }
            }
        }
        $definition = $snapshot !== null ? TemplateContext::fromSnapshot($snapshot) : $template->definition();
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
            'presentation' => TemplatePresentation::presentation($definition, [
                'institution' => route('logos.institution', ['v' => $this->logos->version($this->logos->institutionPath())]),
                'faculty' => $this->logos->facultyUrl(data_get($syllabus->contexto_academico, 'career.faculty_id')),
            ]),
            'template_variables' => $variables,
            'definition' => TemplatePresentation::definition($definition),
            'sections' => $frozen['sections'] ?? $archivedView['sections'] ?? $template->sections
                ->map(fn (TemplateSection $section): array => SyllabusSectionPayload::build($section, fn (FieldDefinition $field): mixed => $values->get($field->id) instanceof FieldValue ? $values->get($field->id)->valor : $field->contenido, ['identification' => $identification]))
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
            'signatures' => $this->signatures->summary($syllabus->id),
            'teacher_signature_required' => ! $this->signatures->signed($syllabus->id, SyllabusSignature::ROLE_TEACHER),
            'teacher_formation_required' => ValidateDraft::formationRequired(),
            // El envío y la solicitud de corrección suben la versión una vez cada uno; cualquier cambio del docente la vuelve a subir.
            'unchanged_since_correction' => $syllabus->estado === 'correccion_solicitada'
                && $revision instanceof SyllabusRevision
                && $revision->hasCorrectionRequest()
                && $syllabus->version_bloqueo === $revision->version_bloqueo_origen + 2,
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
     * `edit_blocked_by_convocation`: pausa, cierre o plazo vencido; la franja de la pantalla ya lo explica.
     *
     * @return array{editable: bool, edit_block_reason: string|null, edit_blocked_by_convocation: bool}
     */
    private function teacherEditability(Syllabus $syllabus): array
    {
        $user = request()->user();
        $ability = $syllabus->estado === 'sin_iniciar' ? 'start' : 'edit';
        if ($user instanceof User && Gate::forUser($user)->allows($ability, $syllabus)) {
            return ['editable' => true, 'edit_block_reason' => null, 'edit_blocked_by_convocation' => false];
        }

        $convocationState = $syllabus->convocation->estado;
        $editableState = in_array($syllabus->estado, ['sin_iniciar', 'borrador', 'correccion_solicitada'], true);
        $byConvocation = $editableState && ($convocationState === Convocation::STATE_CLOSED
            || ! $syllabus->convocation->isRunning()
            || $syllabus->convocation->deadlinePassed());
        $reason = match (true) {
            ! $editableState => 'El sílabo no admite cambios en su estado actual.',
            $convocationState === Convocation::STATE_CLOSED => 'La convocatoria está cerrada: el sílabo queda solo para consulta.',
            ! $syllabus->convocation->isRunning() => 'La convocatoria está en pausa. Podrá seguir editando cuando se reanude.',
            $syllabus->convocation->deadlinePassed() => 'El plazo de entrega venció. Pida una prórroga a Administración.',
            default => 'No tiene una asignación vigente para editar este sílabo.',
        };

        return ['editable' => false, 'edit_block_reason' => $reason, 'edit_blocked_by_convocation' => $byConvocation];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function readOnlyForm(array $sections): array
    {
        $ids = collect($sections)->flatMap(fn (array $section): array => $section['fields'])->pluck('definition_id')->filter()->all();
        $definitions = FieldDefinition::withoutGlobalScopes()->whereIn('definicion_campo_id', $ids)->get()->keyBy('id');

        foreach ($sections as &$section) {
            $section['id'] ??= $section['key'];
            foreach ($section['fields'] as &$field) {
                $field['teacher_editable'] = $field['quien_llena'] === FieldDefinition::TEACHER;
                $field['required'] = $field['quien_llena'] !== FieldDefinition::INSTITUTIONAL;
                $definition = $definitions->get($field['definition_id'] ?? '');
                if ($definition instanceof FieldDefinition && $field['required'] && FieldResponse::enabled()) {
                    $field['template'] = FieldResponse::template($definition->contenido);
                }
            }
        }

        return $sections;
    }
}
