<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\ImportScheduledAssignments;
use App\Modules\Academic\Presentation\Http\Requests\ImportScheduledAssignmentsRequest;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetReader;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetTemplate;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScheduledImportController extends Controller
{
    public function template(SpreadsheetTemplate $template): StreamedResponse
    {
        return $template->download('plantilla-materias-y-asignaciones.xlsx', array_values(ImportScheduledAssignments::COLUMNS), ['SW-P1-001', 'A', 'Matutina', 'docente@ueb.edu.ec']);
    }

    public function import(ImportScheduledAssignmentsRequest $request, SpreadsheetReader $reader, ImportScheduledAssignments $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $rows = $reader->read($request->file('file'), ImportScheduledAssignments::COLUMNS);

        return response()->json($action->execute($rows, $request->string('period_id')->toString(), $actor, $request, $request->boolean('confirm')));
    }
}
