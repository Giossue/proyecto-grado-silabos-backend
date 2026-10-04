<?php

namespace App\Modules\Library\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Documents\Application\SignatureWorkflow;
use App\Modules\Library\Application\Actions\VerifyBibliography;
use App\Modules\Library\Application\LibraryReviews;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Library\Presentation\Http\Requests\CommentLibraryReviewRequest;
use App\Modules\Library\Presentation\Http\Requests\VerifyLibraryReviewRequest;
use App\Modules\Library\Presentation\Http\Requests\ViewLibraryReviewsRequest;
use App\Modules\Syllabus\Application\Actions\RequestSyllabusCorrection;
use App\Modules\Syllabus\Application\BibliographySections;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CorrectionRequest;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
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
        $state = $request->validated('state');
        $search = is_string($request->validated('q')) ? trim($request->validated('q')) : null;
        $page = $this->reviews->query(is_string($state) ? $state : null, $search)
            ->with('sender:id,nombre')
            ->paginate(15)
            ->withQueryString();
        $syllabi = $this->syllabi(array_values(collect($page->items())->pluck('silabo_id')->all()));

        return response()->json([
            'filters' => ['q' => $search ?: null, 'state' => $state],
            'reviews' => $page->through(fn (LibrarySubmission $submission): array => [
                ...$this->summary($syllabi->get($submission->getAttribute('silabo_id')), (int) $submission->getAttribute('numero_revision')),
                'revision_id' => $submission->getAttribute('revision_id'),
                'sent_at' => $submission->enviado_en->toIso8601String(),
                'sent_by' => $submission->sender->nombre,
                'state' => $submission->getAttribute('estado_envio'),
            ]),
        ]);
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
            && ReviewStage::ofRevision($revision) === ReviewStage::LIBRARY;
        $snapshot = $revision->fotografia;
        $mapping = is_array($snapshot['document_mapping'] ?? null) ? $snapshot['document_mapping'] : [];

        return response()->json(['review' => [
            ...$this->summary($syllabus, $revision->numero_revision),
            'revision_id' => $revision->id,
            'sent_at' => $submission->enviado_en->toIso8601String(),
            'sent_by' => $submission->sender->nombre,
            'state' => $state,
            'can_comment' => $reviewable,
            'can_verify' => $reviewable,
            'verified_at' => $submission->verificado_en?->toIso8601String(),
            'verified_by' => $submission->verifier?->nombre,
            'presentation' => [
                'appearance' => TemplateAppearance::fromMapping($mapping),
                'title' => TemplateTitleBlock::fromMapping($mapping)['text'],
                'logos' => [
                    'institution' => route('logos.institution', ['v' => $this->logos->version($this->logos->institutionPath())]),
                    'faculty' => asset('images/silabo/facultad.jpeg').'?v='.$this->logos->version(public_path('images/silabo/facultad.jpeg')),
                ],
            ],
            'template_variables' => is_array($snapshot['template_variables'] ?? null) ? $snapshot['template_variables'] : [],
            'sections' => BibliographySections::of($snapshot),
            'comments' => self::comments($syllabus),
        ]]);
    }

    public function comment(SyllabusRevision $revision, CommentLibraryReviewRequest $request, RequestSyllabusCorrection $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($revision, trim($request->string('comment')->toString()), $actor, $request);

        return response()->json(['message' => 'Comentario enviado. El docente corregirá el sílabo y lo reenviará; volverá a biblioteca.'], 201);
    }

    public function verify(SyllabusRevision $revision, VerifyLibraryReviewRequest $request, VerifyBibliography $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($revision, $actor, $request);

        return response()->json(['message' => 'Bibliografía aprobada. El sílabo pasa a coordinación.'], 201);
    }

    /** @return list<array{comment: string, commented_at: string, commented_by: string, revision_number: int}> */
    public static function comments(Syllabus $syllabus): array
    {
        return array_values(CorrectionRequest::query()
            ->where('etapa_solicitud', ReviewStage::LIBRARY)
            ->whereHas('revision', fn ($query) => $query->where('silabo_id', $syllabus->id))
            ->with(['requester:id,nombre', 'revision:id,numero_revision'])
            ->latest('solicitado_en')
            ->get()
            ->map(fn (CorrectionRequest $correction): array => [
                'comment' => $correction->justificacion,
                'commented_at' => $correction->solicitado_en->toIso8601String(),
                'commented_by' => $correction->requester->nombre,
                'revision_number' => $correction->revision->numero_revision,
            ])
            ->all());
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
                'parallel.scheduledSubject.subject',
                'teachers:id,nombre',
            ])
            ->get()
            ->keyBy('id');
    }

    /** @return array<string, mixed> */
    private function summary(?Syllabus $syllabus, int $revisionNumber): array
    {
        return [
            'syllabus_id' => $syllabus?->id,
            'subject' => $syllabus?->academicSubjectName(),
            'code' => $syllabus?->academicSubjectCode(),
            'career' => $syllabus?->convocation->career->nombre,
            'period' => $syllabus?->convocation->process->academicPeriod->codigo,
            'parallels' => $syllabus === null ? [] : [$syllabus->parallel->codigo],
            'teachers' => $syllabus?->teachers->pluck('nombre')->unique()->values()->all() ?? [],
            'revision_number' => $revisionNumber,
        ];
    }
}
