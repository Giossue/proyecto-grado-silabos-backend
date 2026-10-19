<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteSyllabusProcess
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly InProgressWork $work,
    ) {}

    public function execute(SyllabusProcess $process, User $actor, Request $request): void
    {
        $activeRole = $this->roles->resolve($request);
        abort_unless($activeRole?->role->codigo_rol === RoleCode::Administrator->value, 403);

        try {
            DB::transaction(function () use ($activeRole, $actor, $process): void {
                $locked = SyllabusProcess::query()->lockForUpdate()->findOrFail($process->id);
                $convocations = Convocation::query()
                    ->where('proceso_id', $locked->id)
                    ->lockForUpdate()
                    ->get();
                $syllabi = Syllabus::query()
                    ->whereIn('convocatoria_id', $convocations->modelKeys())
                    ->lockForUpdate()
                    ->get();

                $periodId = $locked->periodo_academico_id;
                $state = $locked->estado;
                $convocationCount = $convocations->count();
                $syllabusCount = $syllabi->count();

                $this->work->purge(array_values(array_map('strval', $syllabi->modelKeys())));
                $convocations->each->delete();
                $locked->delete();

                $this->audit->execute(
                    actorId: $actor->id,
                    roleAssignmentId: $activeRole->id,
                    action: 'proceso_silabos.eliminado',
                    resourceType: 'proceso_silabos',
                    resourceId: $process->id,
                    result: 'exito',
                    metadata: [
                        'period_id' => $periodId,
                        'previous_state' => $state,
                        'deleted_convocations' => $convocationCount,
                        'deleted_syllabi' => $syllabusCount,
                    ],
                );
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23503') {
                throw ValidationException::withMessages([
                    'process' => 'No se puede eliminar porque contiene actividad o historial relacionado.',
                ]);
            }

            throw $exception;
        }
    }
}
