<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\ImportScheduledAssignments;
use App\Modules\Academic\Presentation\Http\Requests\ImportScheduledAssignmentsRequest;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetReader;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScheduledImportController extends Controller
{
    public function template(Request $request, SpreadsheetTemplate $template, ImportScheduledAssignments $action): StreamedResponse
    {
        $options = $action->templateOptions($request);

        return $template->download('plantilla-materias-y-asignaciones.xlsx', array_values(ImportScheduledAssignments::COLUMNS), [
            0 => $options['subjects'],
            2 => ['Matutina', 'Vespertina', 'Nocturna'],
            3 => $options['teachers'],
        ], [
            'Una fila por paralelo; no deje filas vacías entre registros.',
            'Código de materia, Jornada y Correo del docente: elija una opción de la lista.',
            'Paralelo: escriba la letra o el código del paralelo, por ejemplo A.',
            'Correo del docente es opcional: vacío crea el paralelo sin docente.',
            'Si el paralelo ya existe en el período, indique un docente para asignarlo.',
        ]);
    }

    public function import(ImportScheduledAssignmentsRequest $request, SpreadsheetReader $reader, ImportScheduledAssignments $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $rows = $reader->read($request->file('file'), ImportScheduledAssignments::COLUMNS);

        return response()->json($action->execute($rows, $request->string('period_id')->toString(), $actor, $request, $request->boolean('confirm')));
    }
}
