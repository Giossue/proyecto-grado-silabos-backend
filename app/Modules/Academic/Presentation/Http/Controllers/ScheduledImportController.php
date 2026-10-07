<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\ImportCareers;
use App\Modules\Academic\Application\Actions\ImportFaculties;
use App\Modules\Academic\Application\Actions\ImportScheduledAssignments;
use App\Modules\Academic\Presentation\Http\Requests\ImportScheduledAssignmentsRequest;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetReader;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetTemplate;
use App\Modules\Identity\Presentation\Http\Requests\ImportSpreadsheetRequest;
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

    public function careersTemplate(Request $request, SpreadsheetTemplate $template, ImportCareers $action): StreamedResponse
    {
        $options = $action->templateOptions($request);

        return $template->download('plantilla-carreras.xlsx', array_values(ImportCareers::COLUMNS), [
            0 => $options['faculties'],
            1 => $options['modalities'],
        ], [
            'Una fila por carrera; no deje filas vacías entre registros.',
            'Facultad y Modalidad: elija una opción de la lista.',
            'Código de carrera: único en el sistema, por ejemplo SW.',
            'Número de ciclos: un entero entre 1 y 30.',
            'Las facultades no se importan: requieren su logo y se crean una por una.',
        ]);
    }

    public function importCareers(ImportSpreadsheetRequest $request, SpreadsheetReader $reader, ImportCareers $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $rows = $reader->read($request->file('file'), ImportCareers::COLUMNS);

        return response()->json($action->execute($rows, $actor, $request, $request->boolean('confirm')));
    }

    public function facultiesTemplate(Request $request, SpreadsheetTemplate $template, ImportFaculties $action): StreamedResponse
    {
        return $template->download('plantilla-facultades.xlsx', array_values(ImportFaculties::COLUMNS), [
            1 => $action->campuses($request),
        ], [
            'Una fila por facultad; no deje filas vacías entre registros.',
            'Campus: elija una opción de la lista.',
            'Logo: PNG con fondo transparente. Péguelo o arrástrelo sobre la fila de su facultad, con «Colocar sobre las celdas» (no «Colocar en celda»).',
            'No importa que la imagen no encaje exacta en la celda: se asigna a la fila donde queda su centro. Una sola imagen por fila.',
            'Hasta 50 facultades por archivo.',
        ], 70);
    }

    public function importFaculties(ImportSpreadsheetRequest $request, SpreadsheetReader $reader, ImportFaculties $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $rows = $reader->readWithImages($request->file('file'), ImportFaculties::COLUMNS);

        return response()->json($action->execute($rows, $actor, $request, $request->boolean('confirm')));
    }
}
