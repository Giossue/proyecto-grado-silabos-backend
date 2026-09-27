<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\RequestCurricularImport;
use App\Modules\Academic\Application\Actions\RetryCurricularImport;
use App\Modules\Academic\Application\Queries\CurricularImportsViewData;
use App\Modules\Academic\Presentation\Http\Requests\RequestCurricularImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurricularImportUploadController extends Controller
{
    public function retry(Request $request, string $import, RetryCurricularImport $action, CurricularImportsViewData $view): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return response()->json($view->detail($action->execute($import, $actor, $request)), 202);
    }

    public function store(RequestCurricularImportRequest $request, RequestCurricularImport $action, CurricularImportsViewData $view): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $files = $request->file('files', []);
        abort_unless(is_array($files), 422);
        $import = $action->execute(array_values($files), $request->validated('source_ids', []), $request->validated('instruction'), $actor, $request);

        return response()->json($view->detail($import), 202);
    }
}
