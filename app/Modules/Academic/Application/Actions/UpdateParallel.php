<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateParallel
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly InProgressWork $work,
    ) {}

    /** @param array{code: string, shift: string} $data */
    public function execute(string $parallelId, array $data, User $actor, Request $request): Parallel
    {
        $careerId = ParallelScope::careerId($this->roles->resolve($request));

        return DB::transaction(function () use ($actor, $careerId, $data, $parallelId, $request): Parallel {
            $parallel = ParallelScope::find($parallelId, $careerId);

            $code = trim($data['code']);
            $duplicate = Parallel::query()
                ->where('asignatura_id', $parallel->asignatura_id)
                ->whereKeyNot($parallel->id)
                ->whereRaw('upper(codigo_paralelo) = ?', [mb_strtoupper($code)])
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'code' => "Ya existe el paralelo {$code} en esta materia.",
                ]);
            }

            $before = ['code' => $parallel->codigo, 'shift' => $parallel->jornada];
            $parallel->fill(['codigo' => $code, 'jornada' => $data['shift']]);
            if (! $parallel->isDirty()) {
                return $parallel;
            }
            if ($this->work->parallelHasSentSyllabus($parallel->id)) {
                throw ValidationException::withMessages([
                    'code' => 'El sílabo de este paralelo ya está en revisión o aprobado y quedaría con el código o la jornada anteriores. Quite y vuelva a asignar al docente para rehacerlo desde cero.',
                ]);
            }
            $parallel->save();
            $this->work->refreshParallel($parallel->id);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: ParallelScope::roleId($this->roles->resolve($request)),
                action: 'academico.paralelo.actualizacion',
                resourceType: 'paralelo',
                resourceId: $parallel->id,
                result: 'exito',
                metadata: [
                    'before_code' => $before['code'],
                    'after_code' => $parallel->codigo,
                    'before_shift' => $before['shift'],
                    'after_shift' => $parallel->jornada,
                ],
            );

            return $parallel;
        });
    }
}
