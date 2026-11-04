<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReplaceParallelTeacher
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly DeleteAcademicRecord $delete,
        private readonly CreateAcademicRecord $create,
    ) {}

    public function execute(string $parallelId, string $userId, User $actor, Request $request): void
    {
        $careerId = ParallelScope::careerId($this->roles->resolve($request));

        DB::transaction(function () use ($actor, $careerId, $parallelId, $request, $userId): void {
            $parallel = ParallelScope::find($parallelId, $careerId);
            $current = TeacherAssignment::query()->where('paralelo_id', $parallel->id)->where('activo', true)->first();
            if ($current !== null) {
                $this->delete->execute('asignacion_docente', $current->id, $actor, $request);
            }
            $this->create->execute('asignacion_docente', ['parallel_id' => $parallel->id, 'user_id' => $userId], $actor, $request);
        });
    }
}
