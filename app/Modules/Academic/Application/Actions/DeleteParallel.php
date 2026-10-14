<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteParallel
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(string $parallelId, User $actor, Request $request): void
    {
        $role = $this->roles->resolve($request);
        $careerId = ParallelScope::careerId($role);

        DB::transaction(function () use ($actor, $careerId, $parallelId, $role): void {
            $parallel = ParallelScope::find($parallelId, $careerId);
            ParallelScope::assertWithoutSyllabus($parallel, 'El paralelo ya tiene sílabo y no puede eliminarse.');
            if (TeacherAssignment::query()->where('paralelo_id', $parallel->id)->exists()) {
                throw ValidationException::withMessages([
                    'record' => 'El paralelo tiene un docente asignado. Quite primero la asignación docente.',
                ]);
            }
            $code = $parallel->codigo;
            $parallel->delete();

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: ParallelScope::roleId($role),
                action: 'academico.paralelo.eliminacion',
                resourceType: 'paralelo',
                resourceId: $parallelId,
                result: 'exito',
                metadata: ['name' => $code],
            );
        });
    }
}
