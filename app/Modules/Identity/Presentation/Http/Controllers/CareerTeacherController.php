<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Application\Actions\CreateCareerTeacher;
use App\Modules\Identity\Application\Actions\RemoveCareerTeacher;
use App\Modules\Identity\Application\Actions\UpdateManagedUserProfile;
use App\Modules\Identity\Presentation\Http\Requests\CreateCareerTeacherRequest;
use App\Modules\Identity\Presentation\Http\Requests\UpdateCareerTeacherRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function update(User $user, UpdateCareerTeacherRequest $request, UpdateManagedUserProfile $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $previousEmail = $user->correo_electronico;
        $updated = $action->execute($user, $request->profileData(), $actor, $request);

        $message = $updated->correo_electronico !== $previousEmail
            ? 'Docente actualizado. La invitación anterior se invalidó y se envió una nueva al correo corregido.'
            : 'Docente actualizado.';

        return response()->json(['message' => $message]);
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
