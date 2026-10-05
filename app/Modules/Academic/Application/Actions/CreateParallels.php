<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodPlanning;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateParallels
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly AcademicPeriodPlanning $periodPlanning,
    ) {}

    /** @param  array{subject_id: string, period_id: string, codes: list<string>, shift: string}  $data */
    public function execute(array $data, User $actor, Request $request): int
    {
        $activeRole = $this->roles->resolve($request);
        if (! $activeRole instanceof RoleAssignment
            || $activeRole->carrera_id === null
            || ! AcademicStructurePermissions::mayCreate($activeRole, 'paralelo')) {
            throw new AuthorizationException('No puede crear paralelos con el rol activo.');
        }
        $this->locks->assertCareerEditable($activeRole->carrera_id);

        return DB::transaction(function () use ($data, $actor, $activeRole): int {
            $subject = Subject::query()
                ->whereKey($data['subject_id'])
                ->where('activo', true)
                ->where('carrera_id', $activeRole->carrera_id)
                ->lockForUpdate()
                ->firstOrFail();
            $period = AcademicPeriod::query()->whereKey($data['period_id'])->firstOrFail();
            $this->periodPlanning->assertMayPlan($period, 'period_id');
            $codes = collect($data['codes'])
                ->map(fn (string $code) => trim($code))
                ->values();
            $existing = Parallel::query()
                ->where('periodo_academico_id', $period->id)
                ->where('asignatura_id', $subject->id)
                ->whereIn('codigo', $codes)
                ->pluck('codigo');

            if ($existing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'codes' => 'Ya existe el paralelo '.$existing->join(', ').' para esta materia en el período.',
                ]);
            }

            foreach ($codes as $code) {
                $parallel = Parallel::query()->create([
                    'periodo_academico_id' => $period->id,
                    'asignatura_id' => $subject->id,
                    'codigo' => $code,
                    'jornada' => $data['shift'],
                ]);
                $this->audit->execute(
                    actorId: $actor->id,
                    roleAssignmentId: $activeRole->id,
                    action: 'academico.paralelo.creacion',
                    resourceType: 'paralelo',
                    resourceId: $parallel->id,
                    result: 'exito',
                    metadata: ['bulk' => true],
                );
            }

            return $codes->count();
        });
    }
}
