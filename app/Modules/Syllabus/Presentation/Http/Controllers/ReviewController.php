<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Syllabus\Application\Actions\AddReviewObservation;
use App\Modules\Syllabus\Application\Actions\ApproveSyllabus;
use App\Modules\Syllabus\Application\Actions\ReopenSyllabus;
use App\Modules\Syllabus\Application\Actions\RequestSyllabusCorrection;
use App\Modules\Syllabus\Application\Actions\ResetSyllabus;
use App\Modules\Syllabus\Application\Actions\ReviewByCoordination;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Application\RevisionDiff;
use App\Modules\Syllabus\Domain\Policies\SyllabusPolicy;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Modules\Syllabus\Presentation\Http\Requests\ApproveSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ReopenSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ResetSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\StoreCorrectionRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\StoreObservationRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ViewReviewsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function __construct(private readonly InstitutionalLogos $logos) {}

    public function index(ViewReviewsRequest $request, ActiveRole $roles): JsonResponse
    {
        $activeRole = $roles->resolve($request);
        $state = $request->string('state')->toString();
        $search = trim($request->string('search')->toString());

        // I-173: coordinación ve su carrera; decanato, todas las carreras de su facultad.
        $query = Syllabus::query()
            ->when(
                $activeRole?->role->codigo_rol === RoleCode::Dean->value,
                fn ($syllabi) => $syllabi->whereHas('convocation.career', fn ($career) => $career->where('facultad_id', $activeRole?->facultad_id)),
                fn ($syllabi) => $syllabi->whereHas('convocation', fn ($convocation) => $convocation->where('carrera_id', $activeRole?->carrera_id)),
            )
            ->whereHas('revisions')
            ->with([
                'convocation.process.academicPeriod:id,codigo',
                'parallel.scheduledSubject.subject:id,nombre,codigo_asignatura',
                'teachers:id,nombre',
                'revisions' => fn ($revision) => $revision
                    ->with(['librarySubmission', 'coordinationReview', 'approval'])
                    ->orderByDesc('numero_revision')
                    ->limit(1),
            ])
            ->withCount(['reviewObservations as unresolved_observations_count' => fn ($observation) => $observation->where('estado', '!=', 'verificada')]);
        if ($state !== '') {
            $query->where('estado', $state);
        } else {
            $query->whereIn('estado', ['en_revision', 'correccion_solicitada', 'aprobado']);
        }
        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $query->whereHas('parallel.scheduledSubject.subject', fn ($subject) => $subject
                ->where('nombre', 'ilike', "%{$escaped}%")
                ->orWhere('codigo_asignatura', 'ilike', "%{$escaped}%"));
        }

        $payload = [
            'filters' => ['state' => $state, 'search' => $search],
            'syllabi' => $query->orderByRaw('guardado_en DESC NULLS LAST')->paginate(15)->withQueryString()
                ->through(function (Syllabus $syllabus): array {
                    $revision = $syllabus->revisions->first();

                    return [
                        'id' => $syllabus->id,
                        'revision_id' => $revision?->id,
                        'revision_number' => $revision?->numero_revision,
                        'subject' => $syllabus->academicSubjectName(),
                        'code' => $syllabus->academicSubjectCode(),
                        'period' => $syllabus->convocation->process->academicPeriod->codigo,
                        'state' => $syllabus->estado,
                        'stage' => $syllabus->estado === 'en_revision' && $revision !== null ? self::stageOf($revision) : null,
                        'teachers' => $syllabus->teachers->pluck('nombre')->values(),
                        'unresolved_observations' => (int) $syllabus->unresolved_observations_count,
                        'submitted_at' => $revision?->enviado_en->toIso8601String(),
                    ];
                }),
        ];

        return response()->json($payload);
    }

    public function show(SyllabusRevision $revision, Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('review', $revision) === true, 403);

        $payload = $this->reviewPayload($revision);

        return response()->json($payload);
    }

    public function storeObservation(
        SyllabusRevision $revision,
        StoreObservationRequest $request,
        AddReviewObservation $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute(
            $revision,
            $request->filled('section_key') ? $request->string('section_key')->toString() : null,
            $request->filled('field_key') ? $request->string('field_key')->toString() : null,
            $request->string('content')->toString(),
            $actor,
            $request,
        );

        $message = 'Observación registrada en esta revisión.';

        return response()->json(['message' => $message]);
    }

    public function requestCorrection(
        SyllabusRevision $revision,
        StoreCorrectionRequest $request,
        RequestSyllabusCorrection $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute(
            $revision,
            $request->string('justification')->toString(),
            $actor,
            $request,
        );

        $message = 'Corrección solicitada; el docente ya puede editar una nueva versión de trabajo.';

        return response()->json(['message' => $message]);
    }

    /** Coordinación aprueba y pasa al decanato; el decanato aprueba y fija la revisión (I-173). */
    public function approve(
        SyllabusRevision $revision,
        ApproveSyllabusRequest $request,
        ApproveSyllabus $approve,
        ReviewByCoordination $reviewByCoordination,
        ActiveRole $roles,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($roles->hasRole($request, RoleCode::Coordinator)) {
            $reviewByCoordination->execute($revision, $actor, $request);

            return response()->json(['message' => "Revisión {$revision->numero_revision} aprobada por coordinación; pasa al decanato."]);
        }
        $approve->execute(
            $revision,
            $request->string('idempotency_key')->toString(),
            $actor,
            $request,
        );

        $message = "Revisión {$revision->numero_revision} aprobada y fijada. Se está generando el PDF para las firmas.";

        return response()->json(['message' => $message]);
    }

    public function reopen(
        Syllabus $syllabus,
        ReopenSyllabusRequest $request,
        ReopenSyllabus $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute(
            $syllabus,
            $request->string('cause')->toString(),
            $request->string('idempotency_key')->toString(),
            $actor,
            $request,
        );

        $message = 'Sílabo reabierto. La aprobación anterior permanece intacta.';

        return response()->json(['message' => $message]);
    }

    public function compare(
        SyllabusRevision $before,
        SyllabusRevision $after,
        Request $request,
        RevisionDiff $diff,
    ): JsonResponse {
        abort_unless($request->user()?->can('view', $before) === true, 403);
        abort_unless($request->user()->can('view', $after) === true, 403);
        if ($before->silabo_id !== $after->silabo_id) {
            throw ValidationException::withMessages(['revisions' => 'Solo pueden compararse revisiones del mismo expediente.']);
        }
        if ($before->numero_revision > $after->numero_revision) {
            [$before, $after] = [$after, $before];
        }
        $syllabus = $before->syllabus()->with(['parallel.scheduledSubject.subject', 'convocation.process.academicPeriod'])->firstOrFail();

        $payload = [
            'syllabus' => [
                'id' => $syllabus->id,
                'subject' => $syllabus->academicSubjectName(),
                'code' => $syllabus->academicSubjectCode(),
                'period' => $syllabus->convocation->process->academicPeriod->codigo,
            ],
            'comparison' => $diff->compare($before, $after),
        ];

        return response()->json($payload);
    }

    /** @return array<string, mixed> */
    private function reviewPayload(SyllabusRevision $revision): array
    {
        $revision->load([
            'submitter:id,nombre',
            'librarySubmission',
            'coordinationReview',
            'syllabus.parallel.scheduledSubject.subject',
            'syllabus.convocation.process.academicPeriod',
            'syllabus.teachers:id,nombre',
            'syllabus.revisions.submitter:id,nombre',
            'syllabus.revisions.observations.creator:id,nombre',
            'syllabus.revisions.correctionRequester:id,nombre',
            'syllabus.revisions.approval.approver:id,nombre',
            'syllabus.revisions.librarySubmission.verifier:id,nombre',
            'syllabus.revisions.coordinationReview.reviewer:id,nombre',
            'syllabus.reopenedApprovals.reopener:id,nombre',
        ]);
        $syllabus = $revision->syllabus;
        $latest = $syllabus->revisions->sortByDesc('numero_revision')->first();
        $latestReopening = $syllabus->reopenedApprovals->sortByDesc('reabierta_en')->first();
        $decisionsAllowed = SyllabusPolicy::convocationAllowsDecisions($syllabus->convocation->estado);
        $activeRole = app(ActiveRole::class)->resolve(request());
        $myStage = $activeRole === null ? null : ReviewStage::forRole($activeRole->role->codigo_rol);
        $stage = $syllabus->estado === 'en_revision' && $latest?->id === $revision->id ? self::stageOf($revision) : null;
        $documentMapping = is_array($revision->fotografia['document_mapping'] ?? null)
            ? $revision->fotografia['document_mapping']
            : [];

        return [
            'syllabus' => [
                'id' => $syllabus->id,
                'subject' => $syllabus->academicSubjectName(),
                'code' => $syllabus->academicSubjectCode(),
                'period' => $syllabus->convocation->process->academicPeriod->codigo,
                'state' => $syllabus->estado,
                'stage' => $stage,
                'teachers' => $syllabus->teachers->pluck('nombre')->values(),
            ],
            'my_stage' => $myStage,
            // Solo el revisor de la etapa vigente decide; los demás consultan.
            'can_decide' => $decisionsAllowed && $stage !== null && $stage === $myStage,
            'can_reopen' => $decisionsAllowed && $syllabus->estado === 'aprobado'
                && in_array($myStage, [ReviewStage::COORDINATION, ReviewStage::DEANERY], true),
            'presentation' => [
                'appearance' => TemplateAppearance::fromMapping($documentMapping),
                'title' => TemplateTitleBlock::fromMapping($documentMapping)['text'],
                'logos' => [
                    'institution' => route('logos.institution', ['v' => $this->logos->version($this->logos->institutionPath())]),
                    'faculty' => asset('images/silabo/facultad.jpeg').'?v='.$this->logos->version(public_path('images/silabo/facultad.jpeg')),
                ],
            ],
            'revision' => [
                'id' => $revision->id,
                'number' => $revision->numero_revision,
                'submitted_at' => $revision->enviado_en->toIso8601String(),
                'submitted_by' => $revision->submitter->nombre,
                'snapshot' => $revision->fotografia,
                'is_current' => $latest?->id === $revision->id,
            ],
            'history' => $syllabus->revisions->sortBy('numero_revision')->map(fn (SyllabusRevision $item): array => [
                'id' => $item->id,
                'number' => $item->numero_revision,
                'submitted_at' => $item->enviado_en->toIso8601String(),
                'submitted_by' => $item->submitter->nombre,
                'library_verified_at' => $item->librarySubmission?->verificado_en?->toIso8601String(),
                'library_verified_by' => $item->librarySubmission?->verifier?->nombre,
                'coordination_reviewed_at' => $item->coordinationReview?->revisado_en->toIso8601String(),
                'coordination_reviewed_by' => $item->coordinationReview?->reviewer->nombre,
                'approved_at' => $item->approval?->aprobado_en->toIso8601String(),
                'approved_by' => $item->approval?->approver->nombre,
            ])->values(),
            'observations' => $this->reviewObservationsPayload($syllabus),
            'correction_request' => ! $revision->hasCorrectionRequest() ? null : [
                'justification' => $revision->correccion_justificacion,
                'stage' => $revision->correccion_etapa,
                'requested_by' => $revision->correctionRequester->nombre,
                'requested_at' => $revision->correccion_solicitada_en->toIso8601String(),
            ],
            'decisions_allowed' => $decisionsAllowed,
            'reset' => [
                'allowed' => $decisionsAllowed && $myStage === ReviewStage::COORDINATION && in_array($syllabus->estado, ResetSyllabus::RESETTABLE_STATES, true),
            ],
            'reopening' => $latestReopening === null ? null : [
                'cause' => $latestReopening->causa_reapertura,
                'reopened_at' => $latestReopening->reabierta_en->toIso8601String(),
                'reopened_by' => $latestReopening->reopener->nombre,
                'origin' => $latestReopening->origen_reapertura,
            ],
        ];
    }

    /** Etapa a partir de las relaciones ya cargadas de la revisión. */
    private static function stageOf(SyllabusRevision $revision): string
    {
        return match (true) {
            $revision->coordinationReview !== null => ReviewStage::DEANERY,
            $revision->librarySubmission?->verificado_en !== null => ReviewStage::COORDINATION,
            default => ReviewStage::LIBRARY,
        };
    }

    /** @return list<array<string, mixed>> */
    private function reviewObservationsPayload(Syllabus $syllabus): array
    {
        $payload = [];
        foreach ($syllabus->revisions->sortBy('numero_revision') as $revision) {
            $requested = $revision->hasCorrectionRequest();
            foreach ($revision->observations as $observation) {
                $payload[] = [
                    'id' => $observation->id,
                    'revision_number' => $revision->numero_revision,
                    'section_key' => $observation->clave_seccion,
                    'field_key' => $observation->clave_campo,
                    'content' => $observation->contenido,
                    'state' => $observation->estado,
                    'requested' => $requested,
                    'created_by' => $observation->creator->nombre,
                    'observado_en' => $observation->observado_en->toIso8601String(),
                ];
            }
        }

        return $payload;
    }

    public function reset(
        Syllabus $syllabus,
        ResetSyllabusRequest $request,
        ResetSyllabus $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($syllabus, $actor, $request);

        $message = 'Sílabo reiniciado. El docente empieza de cero con la malla y la plantilla actuales; el historial se conserva.';

        return response()->json(['message' => $message]);
    }
}
