<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\CreateAcademicRecord;
use App\Modules\Academic\Application\Actions\DeleteAcademicRecord;
use App\Modules\Academic\Application\Actions\ReplaceCoordinator;
use App\Modules\Academic\Application\Actions\SetAcademicRecordStatus;
use App\Modules\Academic\Application\Actions\UpdateAcademicRecord;
use App\Modules\Academic\Application\Queries\AcademicStructureViewData;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Presentation\Http\Requests\ManageAcademicGovernanceRequest;
use App\Modules\Academic\Presentation\Http\Requests\ReplaceCoordinatorRequest;
use App\Modules\Academic\Presentation\Http\Requests\SetAcademicRecordStatusRequest;
use App\Modules\Academic\Presentation\Http\Requests\StoreAcademicRecordRequest;
use App\Modules\Academic\Presentation\Http\Requests\UpdateAcademicRecordRequest;
use Illuminate\Http\JsonResponse;

class AcademicGovernanceController extends Controller
{
    public function index(
        ManageAcademicGovernanceRequest $request,
        AcademicStructureViewData $viewData,
    ): JsonResponse {
        $routeSection = $request->route('section');
        $section = match ($routeSection) {
            null, 'facultades' => 'faculties',
            'carreras' => 'careers',
            'periodos-academicos' => 'academic-periods',
            default => abort(404),
        };

        $payload = [
            ...$viewData->governance(),
            'section' => $section,
        ];

        return response()->json($payload);
    }

    public function store(
        string $entity,
        StoreAcademicRecordRequest $request,
        CreateAcademicRecord $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($entity, $request->validated(), $actor, $request);

        return response()->json(['message' => 'Registro institucional creado.'], 201);
    }

    /** Nombra o reemplaza la coordinación de una carrera en un paso (I-39). */
    public function replaceCoordinator(
        Career $career,
        ReplaceCoordinatorRequest $request,
        ReplaceCoordinator $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var array{incoming_user_id: string} $data */
        $data = $request->validated();
        $result = $action->execute($career, $data, $actor, $request);

        $message = $result['previous_user_id'] === null
            ? "Coordinación de {$career->nombre} asignada."
            : "Coordinación de {$career->nombre} reemplazada.";

        return response()->json(['message' => $message]);
    }

    public function setStatus(
        string $entity,
        string $record,
        SetAcademicRecordStatusRequest $request,
        SetAcademicRecordStatus $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $active = $request->boolean('active');
        $action->execute($entity, $record, $active, $actor, $request);

        $message = $active
            ? 'Registro reactivado.'
            : 'Registro desactivado sin borrar su historial.';

        return response()->json(['message' => $message]);
    }

    public function update(
        string $entity,
        string $record,
        UpdateAcademicRecordRequest $request,
        UpdateAcademicRecord $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($entity, $record, $request->validated(), $actor, $request);

        return response()->json(['message' => 'Datos institucionales actualizados y auditados.']);
    }

    public function destroy(
        string $entity,
        string $record,
        ManageAcademicGovernanceRequest $request,
        DeleteAcademicRecord $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($entity, $record, $actor, $request);

        return response()->json(['message' => 'Registro institucional eliminado.']);
    }
}
