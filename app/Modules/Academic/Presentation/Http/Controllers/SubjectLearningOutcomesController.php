<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\SaveSubjectLearningOutcomes;
use App\Modules\Academic\Application\Queries\SubjectLearningOutcomesViewData;
use App\Modules\Academic\Presentation\Http\Requests\ManageCareerAcademicStructureRequest;
use App\Modules\Academic\Presentation\Http\Requests\SaveSubjectLearningOutcomesRequest;
use Illuminate\Http\JsonResponse;

class SubjectLearningOutcomesController extends Controller
{
    public function show(string $subject, ManageCareerAcademicStructureRequest $request, SubjectLearningOutcomesViewData $viewData): JsonResponse
    {
        return response()->json($viewData->forSubject($viewData->subject($subject, $request)));
    }

    public function update(string $subject, SaveSubjectLearningOutcomesRequest $request, SaveSubjectLearningOutcomes $action, SubjectLearningOutcomesViewData $viewData): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var array{source_name: string, source_locator: string, outcomes: list<array{id?: string, text: string}>, confirmed: bool, expected_fingerprint: string|null, confirm_purge?: bool} $data */
        $data = $request->validated();
        $saved = $action->execute($subject, $data, $actor, $request);

        return response()->json([
            ...$viewData->forSubject($saved),
            'message' => 'Resultados de aprendizaje confirmados.',
        ]);
    }
}
