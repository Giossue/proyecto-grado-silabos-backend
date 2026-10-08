<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\ImportCurriculum;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetReader;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetTemplate;
use App\Modules\Identity\Presentation\Http\Requests\ImportSpreadsheetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CurriculumImportController extends Controller
{
    public function template(Request $request, SpreadsheetTemplate $template, ImportCurriculum $action): StreamedResponse
    {
        $options = $action->templateOptions($request);

        return $template->download('plantilla-malla.xlsx', array_values(ImportCurriculum::COLUMNS), [
            3 => $options['units'],
            4 => $options['modalities'],
        ], [
            'Una fila por materia; no deje filas vacías entre registros.',
            'Código: único en la malla, por ejemplo SW-B1-001.',
            'Ciclo: un número entre 1 y el número de ciclos de la carrera.',
            'Unidad de organización y Modalidad: elija una opción de la lista (la modalidad es opcional).',
            'Horas y créditos: números; las horas totales se calculan solas.',
            'Prerrequisitos (de un ciclo anterior) y correquisitos (del mismo ciclo): códigos separados por coma; pueden ser materias de la malla o del mismo archivo.',
        ]);
    }

    public function store(ImportSpreadsheetRequest $request, SpreadsheetReader $reader, ImportCurriculum $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $rows = $reader->read($request->file('file'), ImportCurriculum::COLUMNS);

        return response()->json($action->execute($rows, $actor, $request, $request->boolean('confirm')));
    }
}
