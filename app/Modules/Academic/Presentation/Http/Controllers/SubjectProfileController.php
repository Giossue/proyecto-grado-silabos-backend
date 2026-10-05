<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\SaveSubjectProfile;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Presentation\Http\Requests\SaveSubjectProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectProfileController extends Controller
{
    public function show(Subject $asignatura, Request $request, SaveSubjectProfile $action): JsonResponse
    {
        $action->authorize($asignatura, $request);

        return response()->json(['profile' => $asignatura->profile]);
    }

    public function update(Subject $asignatura, SaveSubjectProfileRequest $request, SaveSubjectProfile $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return response()->json(['profile' => $action->execute($asignatura, $request->validated(), $actor, $request)]);
    }
}
