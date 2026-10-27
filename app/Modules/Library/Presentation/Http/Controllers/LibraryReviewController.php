<?php

namespace App\Modules\Library\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Application\TemplatePresentation;
use App\Modules\Documents\Application\SignatureWorkflow;
use App\Modules\Library\Application\Actions\VerifyBibliography;
use App\Modules\Library\Application\LibraryCommentReasons;
use App\Modules\Library\Application\LibraryReviews;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Library\Presentation\Http\Requests\CommentLibraryReviewRequest;
use App\Modules\Library\Presentation\Http\Requests\VerifyLibraryReviewRequest;
use App\Modules\Library\Presentation\Http\Requests\ViewLibraryReviewsRequest;
use App\Modules\Syllabus\Application\Actions\RequestSyllabusCorrection;
use App\Modules\Syllabus\Application\BibliographySections;
use App\Modules\Syllabus\Application\ReviewComments;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Application\SnapshotDocuments;
use App\Modules\Syllabus\Domain\Policies\SyllabusPolicy;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\InstitutionDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class LibraryReviewController extends Controller
{
    public function __construct(
        private readonly LibraryReviews $reviews,
        private readonly InstitutionalLogos $logos,
    ) {}

    public function index(ViewLibraryReviewsRequest $request): JsonResponse
    {
        return response()->json($this->listing($request, LibraryReviews::PERIOD_CURRENT));
    }

    public function history(ViewLibraryReviewsRequest $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->validated('period');

        return response()->json([
            ...$this->listing(
                $request,
                LibraryReviews::PERIOD_PAST,
                is_string($period) ? $period : null,
                $request->boolean('mine') && $user instanceof User ? $user->id : null,
            ),
            'periods' => AcademicPeriod::query()->whereDate('fecha_fin_periodo', '<', InstitutionDate::todayOn())->orderByDesc('fecha_fin_periodo')->get()
                ->map(fn (AcademicPeriod $item): array => ['id' => $item->id, 'code' => $item->codigo])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function listing(ViewLibraryReviewsRequest $request, string $period, ?string $periodId = null, ?string $handledBy = null): array
    {
        $state = $request->validated('state');
        $search = is_string($request->validated('q')) ? trim($request->validated('q')) : null;
        $faculty = $request->validated('faculty');
        $faculty = is_string($faculty) ? $faculty : null;
        $page = $this->reviews->query(is_string($state) ? $state : null, $search, $faculty, $period, $periodId, $handledBy)
            ->with('revision.submitter:id,nombre')
            ->paginate(20)
            ->withQueryString();
        $syllabi = $this->syllabi(array_values(collect($page->items())->pluck('silabo_id')->all()));

        return [
            'filters' => ['q' => $search ?: null, 'state' => $state, 'faculty' => $faculty, 'period' => $periodId, 'mine' => $handledBy !== null],
            'faculties' => Faculty::query()->where('facultad_activa', true)->orderBy('nombre_facultad')->get()
                ->map(fn (Faculty $item): array => ['id' => $item->id, 'name' => $item->nombre])->all(),
            'reviews' => $page->through(fn (LibrarySubmission $submission): array => [
                ...$this->summary($syllabi->get($submission->getAttribute('silabo_id')), (int) $submission->getAttribute('numero_revision')),
                'revision_id' => $submission->getAttribute('revision_id'),
                'sent_at' => $submission->revision->enviado_en->toIso8601String(),
                'sent_by' => $submission->revision->submitter->nombre,
                'state' => $submission->getAttribute('estado_envio'),
            ]),
        ];
    }

    public function show(SyllabusRevision $revision, ViewLibraryReviewsRequest $request, SignatureWorkflow $workflow): JsonResponse
    {
        $artifact = $workflow->artifactFor($revision);
        $submission = $workflow->submission($revision);
        abort_if($submission === null, 404);
        $syllabus = $this->syllabi([$revision->silabo_id])->get($revision->silabo_id);
        abort_if($syllabus === null, 404);
        $state = $this->reviews->stateOf($submission);
        $reviewable = $state === LibraryReviews::STATE_PENDING
            && $syllabus->estado === 'en_revision'
            && ReviewStage::ofRevision($revision) === ReviewStage::LIBRARY
            && SyllabusPolicy::convocationAllowsDecisions($syllabus->convocation()->value('estado'));
        $snapshot = SnapshotDocuments::present($revision->fotografia);
        $definition = TemplateContext::fromSnapshot($snapshot);

        return response()->json(['review' => [
            ...$this->summary($syllabus, $revision->numero_revision),
            'revision_id' => $revision->id,
            'sent_at' => $submission->revision->enviado_en->toIso8601String(),
            'sent_by' => $submission->revision->submitter->nombre,
            'state' => $state,
            'can_comment' => $reviewable,
            'can_verify' => $reviewable,
            'verified_at' => $revision->approval?->aprobado_en->toIso8601String(),
            'verified_by' => $revision->approval?->approver->nombre,
            'presentation' => TemplatePresentation::presentation($definition, [
                'institution' => route('logos.institution', ['v' => $this->logos->version($this->logos->institutionPath())]),
                'faculty' => asset('images/silabo/facultad.jpeg').'?v='.$this->logos->version(public_path('images/silabo/facultad.jpeg')),
            ]),
            'template_variables' => is_array($snapshot['template_variables'] ?? null) ? $snapshot['template_variables'] : [],
            'sections' => BibliographySections::of($snapshot),
            'comments' => self::comments($syllabus),
            'comment_reasons' => LibraryCommentReasons::options(),
        ]]);
    }

    public function comment(SyllabusRevision $revision, CommentLibraryReviewRequest $request, RequestSyllabusCorrection $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /**
         * @var list<string> $reasons
         */
        $reasons = $request->validated('reasons');
        $other = $request->validated('other');
        $action->execute($revision, LibraryCommentReasons::text($reasons, is_string($other) ? $other : null), $actor, $request);

        return response()->json(['message' => 'Comentario enviado. El docente corregirá el sílabo y lo reenviará; volverá a biblioteca.'], 201);
    }

    public function verify(SyllabusRevision $revision, VerifyLibraryReviewRequest $request, VerifyBibliography $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($revision, $actor, $request);

        return response()->json(['message' => 'Bibliografía aprobada. El sílabo queda aprobado y se genera el PDF para las firmas.'], 201);
    }

    /**
     * @return list<array{comment: string, commented_at: string, commented_by: string, revision_number: int}>
     */
    public static function comments(Syllabus $syllabus): array
    {
        return array_map(fn (array $comment): array => [
            'comment' => $comment['comment'],
            'commented_at' => $comment['commented_at'],
            'commented_by' => $comment['commented_by'],
            'revision_number' => $comment['revision_number'],
        ], ReviewComments::forSyllabus($syllabus, ReviewStage::LIBRARY));
    }

    /**
     * @param  list<mixed>  $ids
     * @return Collection<string, Syllabus>
     */
    private function syllabi(array $ids): Collection
    {
        return Syllabus::query()
            ->whereKey($ids)
            ->with([
                'convocation.career:id,nombre',
                'convocation.process.academicPeriod',
                'parallel.subject',
                'teachers:id,nombre',
            ])
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(?Syllabus $syllabus, int $revisionNumber): array
    {
        return [
            'syllabus_id' => $syllabus?->id,
            'subject' => $syllabus?->academicSubjectName(),
            'code' => $syllabus?->academicSubjectCode(),
            'career' => $syllabus?->convocation->career->nombre,
            'period' => $syllabus?->convocation->process->academicPeriod->codigo,
            'parallels' => $syllabus === null ? [] : [$syllabus->academicParallelCode()],
            'teachers' => $syllabus?->teachers->pluck('nombre')->unique()->values()->all() ?? [],
            'revision_number' => $revisionNumber,
        ];
    }
}
