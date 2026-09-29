<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Application\Actions\AddReviewObservation;
use App\Modules\Syllabus\Application\Actions\ApproveSyllabus;
use App\Modules\Syllabus\Application\Actions\ReopenSyllabus;
use App\Modules\Syllabus\Application\Actions\RequestSyllabusCorrection;
use App\Modules\Syllabus\Application\Actions\ResetSyllabus;
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

        $query = Syllabus::query()
            ->whereHas('convocation', fn ($convocation) => $convocation->where('carrera_id', $activeRole?->carrera_id))
            ->whereHas('revisions')
            ->with([
                'convocation.process.academicPeriod:id,codigo',
                'subject:id,nombre,codigo_asignatura',
                'teachers:id,nombre',
                'revisions' => fn ($revision) => $revision
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
            $query->whereHas('subject', fn ($subject) => $subject
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

    public function approve(
        SyllabusRevision $revision,
        ApproveSyllabusRequest $request,
        ApproveSyllabus $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute(
            $revision,
            $request->string('idempotency_key')->toString(),
            $actor,
            $request,
        );

        $message = "Revisión {$revision->numero_revision} aprobada y fijada.";

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
        $syllabus = $before->syllabus()->with(['subject', 'convocation.process.academicPeriod'])->firstOrFail();

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
            'syllabus.subject',
            'syllabus.convocation.process.academicPeriod',
            'syllabus.teachers:id,nombre',
            'syllabus.revisions.submitter:id,nombre',
            'syllabus.revisions.observations.creator:id,nombre',
            'syllabus.revisions.correctionRequest',
            'syllabus.revisions.approval.approver:id,nombre',
            'syllabus.reopenings.reopener:id,nombre',
        ]);
        $syllabus = $revision->syllabus;
        $latest = $syllabus->revisions->sortByDesc('numero_revision')->first();
        $latestReopening = $syllabus->reopenings->sortByDesc('reabierto_en')->first();
        $decisionsAllowed = SyllabusPolicy::convocationAllowsDecisions($syllabus->convocation->estado);
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
                'teachers' => $syllabus->teachers->pluck('nombre')->values(),
            ],
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
                'approved_at' => $item->approval?->aprobado_en->toIso8601String(),
            ])->values(),
            'observations' => $this->reviewObservationsPayload($syllabus),
            'correction_request' => $revision->correctionRequest === null ? null : [
                'justification' => $revision->correctionRequest->justificacion,
                'requested_at' => $revision->correctionRequest->solicitado_en->toIso8601String(),
            ],
            'decisions_allowed' => $decisionsAllowed,
            'reset' => [
                'allowed' => $decisionsAllowed && in_array($syllabus->estado, ResetSyllabus::RESETTABLE_STATES, true),
            ],
            'reopening' => $latestReopening === null ? null : [
                'cause' => $latestReopening->causa,
                'reopened_at' => $latestReopening->reabierto_en->toIso8601String(),
                'reopened_by' => $latestReopening->reopener->nombre,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function reviewObservationsPayload(Syllabus $syllabus): array
    {
        $payload = [];
        foreach ($syllabus->revisions->sortBy('numero_revision') as $revision) {
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
        $action->execute($syllabus, $request->string('reason')->toString(), $actor, $request);

        $message = 'Sílabo reiniciado. El docente empieza de cero con la malla y la plantilla actuales; el historial se conserva.';

        return response()->json(['message' => $message]);
    }
}
