<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Application\Actions\CreateCareerTeacher;
use App\Modules\Identity\Application\Actions\ImportCareerTeachers;
use App\Modules\Identity\Application\Actions\RemoveCareerTeacher;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetReader;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetTemplate;
use App\Modules\Identity\Domain\PersonNameColumns;
use App\Modules\Identity\Presentation\Http\Requests\CreateCareerTeacherRequest;
use App\Modules\Identity\Presentation\Http\Requests\ImportSpreadsheetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CareerTeacherController extends Controller
{
    public function store(CreateCareerTeacherRequest $request, CreateCareerTeacher $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $result = $action->execute($request->teacherData(), $actor, $request);

        $message = $result['created']
            ? 'Docente creado para su carrera. Recibirá un enlace de activación por correo.'
            : ($result['attached']
                ? 'Docente incorporado a su carrera. Conserva su nombre y acceso actuales.'
                : 'El docente ya está disponible en su carrera. Conserva su nombre y acceso actuales.');

        return response()->json(['message' => $message]);
    }

    public function importTemplate(SpreadsheetTemplate $template): StreamedResponse
    {
        Gate::authorize('createCareerTeacher', User::class);

        return $template->download('plantilla-docentes.xlsx', array_values(ImportCareerTeachers::COLUMNS), [], [
            ...PersonNameColumns::TEACHER_NOTES,
            'Correo: use el correo institucional; si la cuenta ya existe, se incorpora a su carrera sin cambiar su nombre.',
        ]);
    }

    public function import(ImportSpreadsheetRequest $request, SpreadsheetReader $reader, ImportCareerTeachers $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::authorize('createCareerTeacher', User::class);
        $rows = $reader->read($request->file('file'), ImportCareerTeachers::COLUMNS);

        return response()->json($action->execute($rows, $actor, $request, $request->boolean('confirm')));
    }

    public function destroy(User $user, Request $request, RemoveCareerTeacher $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $result = $action->execute($user, $actor, $request);
        $count = $result['removed_parallel_assignments'];

        $message = $count === 0
            ? 'Docente retirado de su carrera.'
            : ($count === 1
                ? 'Docente retirado de su carrera; su paralelo quedó sin docente.'
                : "Docente retirado de su carrera; sus {$count} paralelos quedaron sin docente.");

        return response()->json(['message' => $message]);
    }
}
