<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\ImportCareers;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetReader;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetTemplate;
use App\Modules\Identity\Presentation\Http\Requests\ImportSpreadsheetRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CareerImportController extends Controller
{
    public function template(Request $request, SpreadsheetTemplate $template, ImportCareers $action): StreamedResponse
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

    public function store(ImportSpreadsheetRequest $request, SpreadsheetReader $reader, ImportCareers $action, ActiveRole $roles): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        // Se autoriza antes de abrir el archivo: quien no puede importar no hace trabajar al lector.
        if (! AcademicStructurePermissions::mayCreate($roles->resolve($request), 'carrera')) {
            throw new AuthorizationException('No puede importar carreras con el rol activo.');
        }
        $rows = $reader->read($request->file('file'), ImportCareers::COLUMNS);

        return response()->json($action->execute($rows, $actor, $request, $request->boolean('confirm')));
    }
}
