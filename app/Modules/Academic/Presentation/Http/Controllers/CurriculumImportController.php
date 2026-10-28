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

        $columns = array_keys(ImportCurriculum::COLUMNS);

        return $template->download('plantilla-malla.xlsx', array_values(ImportCurriculum::COLUMNS), [
            array_search('unidad', $columns, true) => $options['units'],
            array_search('modalidad', $columns, true) => $options['modalities'],
            array_search('genera_silabo', $columns, true) => $options['syllabus'],
        ], [
            'Una fila por materia; no deje filas vacías entre registros.',
            'Código: único en la malla, por ejemplo SW-B1-001.',
            'Ciclo: un número entre 1 y el número de ciclos de la carrera.',
            'Unidad de organización y Modalidad: elija una opción de la lista (la modalidad es opcional).',
            'Genera sílabo: Sí o No. Si la materia no lleva sílabo, elija No; si la deja vacía se toma Sí.',
            'Horas y créditos: números; las horas totales se calculan solas.',
            'Prerrequisitos (de un ciclo anterior) y correquisitos (del mismo ciclo): códigos separados por coma; pueden ser materias de la malla o del mismo archivo.',
        ]);
    }

    public function store(ImportSpreadsheetRequest $request, SpreadsheetReader $reader, ImportCurriculum $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $rows = $reader->read($request->file('file'), ImportCurriculum::COLUMNS, ImportCurriculum::OPTIONAL_COLUMNS);

        return response()->json($action->execute($rows, $actor, $request, $request->boolean('confirm')));
    }
}
