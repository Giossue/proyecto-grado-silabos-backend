<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Application\TemplatePresentation;
use App\Modules\Documents\Application\SyllabusSignatures;
use App\Modules\Documents\Domain\Data\DecisionSignature;
use App\Modules\Documents\Domain\SigningStatement;
use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Application\Actions\ApproveSyllabus;
use App\Modules\Syllabus\Application\Actions\DeleteSyllabus;
use App\Modules\Syllabus\Application\Actions\RequestSyllabusCorrection;
use App\Modules\Syllabus\Application\Actions\ResetSyllabus;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Application\RevisionDiff;
use App\Modules\Syllabus\Application\SnapshotDocuments;
use App\Modules\Syllabus\Domain\Policies\SyllabusPolicy;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Modules\Syllabus\Presentation\Http\Requests\ApproveSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\DeleteSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ResetSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\StoreCorrectionRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ViewReviewsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function __construct(
        private readonly InstitutionalLogos $logos,
        private readonly SyllabusSignatures $signatures,
    ) {}

    public function index(ViewReviewsRequest $request, ActiveRole $roles): JsonResponse
    {
        $activeRole = $roles->resolve($request);
        $state = $request->string('state')->toString();
        $search = trim($request->string('search')->toString());

        $query = Syllabus::query()
            ->whereHas('convocation', fn ($convocation) => $convocation->where('carrera_id', $activeRole?->carrera_id))
            ->whereHas('revisions')
            ->with([
                'convocation.process.academicPeriod:id,codigo',
                'parallel.subject:id,nombre,codigo_asignatura',
                'teachers:id,nombre',
                'revisions' => fn ($revision) => $revision
                    ->with(['librarySubmission', 'coordinationReview', 'approval'])
                    ->orderByDesc('numero_revision')
                    ->limit(1),
            ]);
        if ($state !== '') {
            $query->where('estado', $state);
        } else {
            $query->whereIn('estado', ['en_revision', 'correccion_solicitada', 'aprobado']);
        }
        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $query->whereHas('parallel.subject', fn ($subject) => $subject
                ->where('nombre', 'ilike', "%{$escaped}%")
                ->orWhere('codigo_asignatura', 'ilike', "%{$escaped}%"));
        }

        $payload = [
            'filters' => ['state' => $state, 'search' => $search],
            'syllabi' => $query->orderByRaw('guardado_en DESC NULLS LAST')->paginate(20)->withQueryString()
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

    public function approve(
        SyllabusRevision $revision,
        ApproveSyllabusRequest $request,
        ApproveSyllabus $approve,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $approve->execute($revision, DecisionSignature::from($request->input('signature')), $actor, $request);

        $message = 'Sílabo firmado y enviado a biblioteca.';

        return response()->json(['message' => $message]);
    }

    public function destroy(
        Syllabus $syllabus,
        DeleteSyllabusRequest $request,
        DeleteSyllabus $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($syllabus, $actor, $request);

        $message = 'Sílabo eliminado con todo su historial. El docente empieza de cero con la malla y la plantilla actuales.';

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
        $syllabus = $before->syllabus()->with(['parallel.subject', 'convocation.process.academicPeriod'])->firstOrFail();

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

    /**
     * @return array<string, mixed>
     */
    private function reviewPayload(SyllabusRevision $revision): array
    {
        $revision->load([
            'submitter:id,nombre',
            'librarySubmission',
            'coordinationReview',
            'syllabus.parallel.subject',
            'syllabus.convocation.process.academicPeriod',
            'syllabus.teachers:id,nombre',
            'syllabus.revisions.submitter:id,nombre',
            'syllabus.revisions.correctionRequester:id,nombre',
            'syllabus.revisions.approval.approver:id,nombre',
            'syllabus.revisions.coordinationReview.reviewer:id,nombre',
        ]);
        $syllabus = $revision->syllabus;
        $latest = $syllabus->revisions->sortByDesc('numero_revision')->first();
        $decisionsAllowed = SyllabusPolicy::convocationAllowsDecisions($syllabus->convocation->estado);
        $activeRole = app(ActiveRole::class)->resolve(request());
        $myStage = $activeRole === null ? null : ReviewStage::forRole($activeRole->role->codigo_rol);
        $stage = $syllabus->estado === 'en_revision' && $latest?->id === $revision->id ? self::stageOf($revision) : null;
        $definition = TemplateContext::fromSnapshot($revision->fotografia);
        $canDecide = $decisionsAllowed && $stage !== null && $stage === $myStage;
        $mustSign = $canDecide && $myStage === ReviewStage::COORDINATION
            && ! $this->signatures->signed($syllabus->id, SyllabusSignature::ROLE_COORDINATION);

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

            'can_decide' => $canDecide,
            'signing_statement' => $mustSign
                ? SigningStatement::for($syllabus->id, SyllabusSignature::ROLE_COORDINATION, $revision->huella_sha256)
                : null,
            'signatures' => $this->signatures->summary($syllabus->id),
            'can_delete' => $decisionsAllowed && $syllabus->estado === 'aprobado' && $myStage === ReviewStage::COORDINATION,
            'presentation' => TemplatePresentation::presentation($definition, [
                'institution' => route('logos.institution', ['v' => $this->logos->version($this->logos->institutionPath())]),
                'faculty' => $this->logos->facultyUrl(data_get($syllabus->contexto_academico, 'career.faculty_id')),
            ]),
            'revision' => [
                'id' => $revision->id,
                'number' => $revision->numero_revision,
                'submitted_at' => $revision->enviado_en->toIso8601String(),
                'submitted_by' => $revision->submitter->nombre,
                'snapshot' => SnapshotDocuments::present($revision->fotografia),
                'is_current' => $latest?->id === $revision->id,
            ],
            'history' => $syllabus->revisions->sortBy('numero_revision')->map(fn (SyllabusRevision $item): array => [
                'id' => $item->id,
                'number' => $item->numero_revision,
                'submitted_at' => $item->enviado_en->toIso8601String(),
                'submitted_by' => $item->submitter->nombre,
                'library_verified_at' => $item->approval?->aprobado_en->toIso8601String(),
                'library_verified_by' => $item->approval?->approver->nombre,
                'coordination_reviewed_at' => $item->coordinationReview?->revisado_en->toIso8601String(),
                'coordination_reviewed_by' => $item->coordinationReview?->reviewer->nombre,
                'approved_at' => $item->approval?->aprobado_en->toIso8601String(),
                'approved_by' => $item->approval?->approver->nombre,
            ])->values(),
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
        ];
    }

    private static function stageOf(SyllabusRevision $revision): string
    {
        return ReviewStage::ofRevision($revision) ?? ReviewStage::LIBRARY;
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
