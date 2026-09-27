<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\ApplyCurricularImport;
use App\Modules\Academic\Application\Queries\CurricularImportsViewData;
use App\Modules\Academic\Presentation\Http\Requests\ApplyCurricularImportRequest;
use App\Modules\Academic\Presentation\Http\Requests\ManageCareerAcademicStructureRequest;
use Illuminate\Http\JsonResponse;

class CurricularImportsController extends Controller
{
    public function index(ManageCareerAcademicStructureRequest $request, CurricularImportsViewData $view): JsonResponse
    {
        return response()->json($view->index($request));
    }

    public function show(string $import, ManageCareerAcademicStructureRequest $request, CurricularImportsViewData $view): JsonResponse
    {
        return response()->json($view->detail($view->find($import, $request)));
    }

    public function apply(string $import, ApplyCurricularImportRequest $request, ApplyCurricularImport $action, CurricularImportsViewData $view): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var array{expected_fingerprint: string, confirmed: bool, items: list<array{id: string, subject_id?: string|null, accept_conflict?: bool}>, confirm_purge?: bool} $data */
        $data = $request->validated();
        $saved = $action->execute($import, $data, $actor, $request);

        return response()->json([...$view->detail($saved), 'message' => 'Datos curriculares confirmados.']);
    }
}
