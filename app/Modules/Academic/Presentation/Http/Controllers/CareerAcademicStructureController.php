<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\CreateAcademicRecord;
use App\Modules\Academic\Application\Actions\CreateParallels;
use App\Modules\Academic\Application\Actions\DeleteAcademicRecord;
use App\Modules\Academic\Application\Actions\DeleteParallel;
use App\Modules\Academic\Application\Actions\MutateCurriculumBuilder;
use App\Modules\Academic\Application\Actions\PreparePeriod;
use App\Modules\Academic\Application\Actions\UpdateCareerAcademicRecord;
use App\Modules\Academic\Application\Actions\UpdateParallel;
use App\Modules\Academic\Application\Queries\AcademicStructureViewData;
use App\Modules\Academic\Presentation\Http\Requests\ManageCareerAcademicStructureRequest;
use App\Modules\Academic\Presentation\Http\Requests\PreparePeriodRequest;
use App\Modules\Academic\Presentation\Http\Requests\StoreAcademicRecordRequest;
use App\Modules\Academic\Presentation\Http\Requests\StoreParallelsRequest;
use App\Modules\Academic\Presentation\Http\Requests\StoreSubjectRequirementRequest;
use App\Modules\Academic\Presentation\Http\Requests\UpdateCareerAcademicRecordRequest;
use App\Modules\Academic\Presentation\Http\Requests\UpdateCurriculumConfigurationRequest;
use App\Modules\Academic\Presentation\Http\Requests\UpdateParallelRequest;
use App\Modules\Academic\Presentation\Http\Requests\UpdateSubjectLayoutRequest;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CareerAcademicStructureController extends Controller
{
    public function curriculumBuilder(
        string $curriculum,
        ManageCareerAcademicStructureRequest $request,
        ActiveRole $roles,
        AcademicStructureViewData $viewData,
    ): JsonResponse {
        $payload = $viewData->curriculumBuilder($this->careerId($request, $roles), $curriculum);

        return response()->json($payload);
    }

    public function scheduledSubjects(
        ManageCareerAcademicStructureRequest $request,
        ActiveRole $roles,
        AcademicStructureViewData $viewData,
    ): JsonResponse {
        $payload = $viewData->scheduledSubjects(
            $this->careerId($request, $roles),
            $request->string('period')->toString() ?: null,
        );

        return response()->json($payload);
    }

    public function teacherAssignments(
        ManageCareerAcademicStructureRequest $request,
        ActiveRole $roles,
        AcademicStructureViewData $viewData,
    ): JsonResponse {
        $payload = [
            ...$viewData->teacherAssignments($this->careerId($request, $roles)),
            'canCreateTeacher' => $request->user()?->can('createCareerTeacher', User::class) === true,
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

        return $this->success($request, 'Registro académico creado dentro de su carrera.');
    }

    public function storeParallels(
        StoreParallelsRequest $request,
        CreateParallels $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var array{subject_id: string, period_id: string, codes: list<string>, shift: string} $data */
        $data = $request->validated();
        $created = $action->execute($data, $actor, $request);

        return $this->success($request, "{$created} paralelos creados dentro de su carrera.");
    }

    public function updateParallel(
        string $parallel,
        UpdateParallelRequest $request,
        UpdateParallel $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var array{code: string, shift: string} $data */
        $data = $request->validated();
        $action->execute($parallel, $data, $actor, $request);

        return $this->success($request, 'Paralelo actualizado.');
    }

    public function destroyParallel(
        string $parallel,
        ManageCareerAcademicStructureRequest $request,
        DeleteParallel $action,
    ): JsonResponse {
        $action->execute($parallel, $this->actor($request), $request);

        return $this->success($request, 'Paralelo eliminado.');
    }

    public function preparePeriod(
        PreparePeriodRequest $request,
        PreparePeriod $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var array{period_id: string, subjects: list<array{id: string, parallels: list<array{code: string, shift: string, teacher_user_id?: string|null}>}>} $data */
        $data = $request->validated();
        $result = $action->execute($data, $actor, $request);

        $message = sprintf(
            'Período preparado: %d %s y %d %s para %d %s.',
            $result['parallels'],
            $result['parallels'] === 1 ? 'paralelo' : 'paralelos',
            $result['assignments'],
            $result['assignments'] === 1 ? 'docente asignado' : 'docentes asignados',
            $result['subjects'],
            $result['subjects'] === 1 ? 'materia' : 'materias',
        );

        return $this->success($request, $message);
    }

    public function update(
        string $entity,
        string $record,
        UpdateCareerAcademicRecordRequest $request,
        UpdateCareerAcademicRecord $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($entity, $record, $request->validated(), $actor, $request);

        return $this->success($request, 'Registro académico actualizado.');
    }

    public function destroy(
        string $entity,
        string $record,
        ManageCareerAcademicStructureRequest $request,
        DeleteAcademicRecord $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($entity, $record, $actor, $request);

        return $this->success($request, 'Registro académico eliminado.');
    }

    public function updateCurriculumConfiguration(
        string $curriculum,
        UpdateCurriculumConfigurationRequest $request,
        MutateCurriculumBuilder $action,
    ): JsonResponse {
        /** @var array{cycle_count: int|string} $data */
        $data = $request->validated();
        $action->updateConfiguration(
            $curriculum,
            $data,
            $this->actor($request),
            $request,
        );

        return $this->success($request, 'Configuración de la malla actualizada.');
    }

    public function storeSubjectRequirement(
        string $curriculum,
        StoreSubjectRequirementRequest $request,
        MutateCurriculumBuilder $action,
    ): JsonResponse {
        $action->createRequirement($curriculum, $request->validated(), $this->actor($request), $request);

        return $this->success($request, 'Relación académica agregada.');
    }

    public function destroySubjectRequirement(
        string $curriculum,
        string $requirement,
        ManageCareerAcademicStructureRequest $request,
        MutateCurriculumBuilder $action,
    ): JsonResponse {
        $action->deleteRequirement($curriculum, $requirement, $this->actor($request), $request);

        return $this->success($request, 'Relación académica eliminada.');
    }

    public function destroySubject(
        string $curriculum,
        string $subject,
        ManageCareerAcademicStructureRequest $request,
        MutateCurriculumBuilder $action,
    ): JsonResponse {
        $action->deleteSubject($curriculum, $subject, $this->actor($request), $request);

        return $this->success($request, 'Materia eliminada de la malla.');
    }

    public function updateSubjectLayout(
        string $curriculum,
        UpdateSubjectLayoutRequest $request,
        MutateCurriculumBuilder $action,
    ): JsonResponse {
        $action->updateSubjectLayout($curriculum, $request->validated(), $this->actor($request), $request);

        return $this->success($request, 'Posición de la materia actualizada.');
    }

    private function careerId(
        ManageCareerAcademicStructureRequest $request,
        ActiveRole $roles,
    ): string {
        $careerId = $roles->resolve($request)?->carrera_id;
        abort_unless(is_string($careerId), 403);

        return $careerId;
    }

    private function actor(ManageCareerAcademicStructureRequest|StoreSubjectRequirementRequest|UpdateCurriculumConfigurationRequest|UpdateSubjectLayoutRequest $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function success(Request $request, string $message): JsonResponse
    {
        return response()->json(['message' => $message]);
    }
}
