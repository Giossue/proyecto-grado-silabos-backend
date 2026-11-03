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
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Application\RevisionDiff;
use App\Modules\Syllabus\Application\SnapshotDocuments;
use App\Modules\Syllabus\Domain\Policies\SyllabusPolicy;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Modules\Syllabus\Presentation\Http\Requests\ApproveSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ResetSyllabusRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\StoreCorrectionRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ViewReviewsRequest;
use App\Support\Search\AccentInsensitive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $query->where('estado', 'en_revision')->whereHas('revisions', fn ($revision) => $revision
            ->whereDoesntHave('librarySubmission')
            ->whereDoesntHave('approval')
            ->whereRaw('numero_revision = (select max(latest.numero_revision) from revisiones_silabo latest where latest.silabo_id = revisiones_silabo.silabo_id)'));
        if ($search !== '') {
            $query->where(fn ($outer) => $outer
                ->whereRaw(AccentInsensitive::matches("datos_academicos_silabo->'subject'->>'name'"), [AccentInsensitive::pattern($search)])
                ->orWhereRaw(AccentInsensitive::matches("datos_academicos_silabo->'subject'->>'code'"), [AccentInsensitive::pattern($search)]));
        }

        $payload = [
            'filters' => ['search' => $search],
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
                'current_revision_id' => $syllabus->revisions()->orderByDesc('numero_revision')->value('revision_silabo_id'),
                'revisions' => $syllabus->revisions()->orderBy('numero_revision')->get(['revision_silabo_id', 'numero_revision', 'enviado_en'])
                    ->map(fn (SyllabusRevision $item): array => ['id' => $item->id, 'number' => $item->numero_revision, 'submitted_at' => $item->enviado_en->toIso8601String()])
                    ->all(),
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
        $decisionBlock = SyllabusPolicy::decisionBlock($syllabus);
        $decisionsAllowed = $decisionBlock === null;
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
                'coordination_reviewed_at' => $item->coordinationReview?->revisado_en->toIso8601String(),
                'coordination_reviewed_by' => $item->coordinationReview?->reviewer->nombre,
                'approved_at' => $item->approval?->aprobado_en->toIso8601String(),
                'approved_by' => $item->approval?->approver->nombre,
            ])->values(),
            'timeline' => $this->timeline($syllabus->revisions->sortBy('numero_revision')->values()),
            'correction_request' => ! $revision->hasCorrectionRequest() ? null : [
                'justification' => $revision->correccion_etapa === ReviewStage::LIBRARY ? null : $revision->correccion_justificacion,
                'stage' => $revision->correccion_etapa,
                'requested_by' => $revision->correccion_etapa === ReviewStage::LIBRARY ? null : $revision->correctionRequester->nombre,
                'requested_at' => $revision->correccion_solicitada_en->toIso8601String(),
            ],
            'decisions_allowed' => $decisionsAllowed,
            'convocation_closed' => ! SyllabusPolicy::convocationAllowsDecisions($syllabus->convocation->estado),
            'decision_block' => $decisionBlock,
            'reset' => [
                'allowed' => $decisionBlock === null && $myStage === ReviewStage::COORDINATION && $syllabus->estado === 'aprobado',
                'erases_history' => $syllabus->estado === 'aprobado',
                'revisions' => $syllabus->revisions->count(),
                'signatures' => SyllabusSignature::query()->where('silabo_id', $syllabus->id)->count(),
                'has_pdf' => $syllabus->estado === 'aprobado',
            ],
        ];
    }

    /**
     * @param  Collection<int, SyllabusRevision>  $revisions
     * @return list<array<string, mixed>>
     */
    private function timeline(Collection $revisions): array
    {
        $events = [];
        foreach ($revisions as $index => $item) {
            $events[] = [
                'kind' => 'enviado',
                'at' => $item->enviado_en->toIso8601String(),
                'revision_id' => $item->id,
                'revision_number' => $item->numero_revision,
                'by' => $item->submitter->nombre,
                'comment' => null,
                'previous_id' => $revisions->get($index - 1)?->id,
            ];
            if ($item->coordinationReview !== null) {
                $events[] = [
                    'kind' => 'coordinacion_aprobo',
                    'at' => $item->coordinationReview->revisado_en->toIso8601String(),
                    'revision_id' => $item->id,
                    'revision_number' => $item->numero_revision,
                    'by' => $item->coordinationReview->reviewer->nombre,
                    'comment' => null,
                    'previous_id' => null,
                ];
            }
            if ($item->hasCorrectionRequest()) {
                $fromLibrary = $item->correccion_etapa === ReviewStage::LIBRARY;
                $events[] = [
                    'kind' => $fromLibrary ? 'devuelto_biblioteca' : 'correccion',
                    'at' => $item->correccion_solicitada_en->toIso8601String(),
                    'revision_id' => $item->id,
                    'revision_number' => $item->numero_revision,
                    'by' => $fromLibrary ? null : $item->correctionRequester->nombre,
                    'comment' => $fromLibrary ? null : $item->correccion_justificacion,
                    'previous_id' => null,
                ];
            }
            if ($item->approval !== null) {
                $events[] = [
                    'kind' => 'aprobado',
                    'at' => $item->approval->aprobado_en->toIso8601String(),
                    'revision_id' => $item->id,
                    'revision_number' => $item->numero_revision,
                    'by' => null,
                    'comment' => null,
                    'previous_id' => null,
                ];
            }
        }
        $weight = ['enviado' => 0, 'coordinacion_aprobo' => 1, 'correccion' => 2, 'devuelto_biblioteca' => 2, 'aprobado' => 3];
        usort($events, fn (array $left, array $right): int => [(string) $right['at'], $right['revision_number'], $weight[$right['kind']]]
            <=> [(string) $left['at'], $left['revision_number'], $weight[$left['kind']]]);

        return $events;
    }

    private static function stageOf(SyllabusRevision $revision): string
    {
        return ReviewStage::ofRevision($revision) ?? ReviewStage::LIBRARY;
    }

    public function reset(
        Syllabus $syllabus,
        ResetSyllabusRequest $request,
        DeleteSyllabus $delete,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $reason = trim($request->string('reason')->toString());
        $delete->execute($syllabus, $actor, $request, $reason);

        return response()->json(['message' => 'Sílabo reiniciado con todo su historial. El docente empieza de cero con la malla y la plantilla actuales.']);
    }
}
