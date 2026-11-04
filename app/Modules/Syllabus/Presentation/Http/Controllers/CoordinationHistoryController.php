<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Application\Queries\CoordinationHistory;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Presentation\Http\Requests\ViewCoordinationHistoryRequest;
use Illuminate\Http\JsonResponse;

class CoordinationHistoryController extends Controller
{
    public function index(ViewCoordinationHistoryRequest $request, ActiveRole $roles, CoordinationHistory $history): JsonResponse
    {
        $careerId = $roles->resolve($request)?->carrera_id;
        abort_unless(is_string($careerId), 403);
        $search = is_string($request->validated('q')) ? trim($request->validated('q')) : '';
        $period = $request->validated('period');
        $period = is_string($period) ? $period : null;

        return response()->json([
            'filters' => ['q' => $search === '' ? null : $search, 'period' => $period],
            'periods' => $history->periods($careerId)
                ->map(fn (AcademicPeriod $item): array => ['id' => $item->id, 'code' => $item->codigo])->values()->all(),
            'syllabi' => $history->page($careerId, $search, $period)->through(function (Syllabus $syllabus): array {
                $revision = $syllabus->revisions->first();

                return [
                    'id' => $syllabus->id,
                    'revision_id' => $revision?->id,
                    'period' => $syllabus->convocation->process->academicPeriod->codigo,
                    'subject' => $syllabus->academicSubjectName(),
                    'code' => $syllabus->academicSubjectCode(),
                    'parallels' => [$syllabus->academicParallelCode()],
                    'author' => $revision?->submitter->nombre,
                    'approved_at' => $revision?->approval?->aprobado_en->toIso8601String(),
                ];
            }),
        ]);
    }
}
