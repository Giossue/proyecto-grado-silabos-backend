<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodPlanning;
use App\Modules\Academic\Application\ScheduledSubjectInheritance;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparePeriod
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduledSubjectInheritance $inheritance,
        private readonly ProcessLocks $locks,
        private readonly AcademicPeriodPlanning $periodPlanning,
    ) {}

    /**
     * @param  array{period_id: string, subjects: list<array{id: string, parallels: list<array{code: string, shift: string, teacher_user_id?: string|null}>}>}  $data
     * @return array{parallels: int, assignments: int, subjects: int}
     */
    public function execute(array $data, User $actor, Request $request): array
    {
        $activeRole = $this->roles->resolve($request);
        if (! $activeRole instanceof RoleAssignment || $activeRole->carrera_id === null) {
            throw new AuthorizationException('No puede preparar periodos con el rol activo.');
        }
        $careerId = $activeRole->carrera_id;
        $this->locks->assertCareerEditable($careerId);

        return DB::transaction(function () use ($actor, $activeRole, $careerId, $data): array {
            $career = Career::query()->whereKey($careerId)->with('faculty')->lockForUpdate()->firstOrFail();
            $period = AcademicPeriod::query()->whereKey($data['period_id'])->lockForUpdate()->firstOrFail();
            $this->periodPlanning->assertMayPlan($period);
            // Solo valida que la facultad tenga campus; ya no se copia en la programación (I-164).
            $this->inheritance->campusFor($career);
            $settingsBySubject = collect($data['subjects'])->keyBy('id');
            $subjectsQuery = Subject::query()
                ->where('activo', true)
                ->where('carrera_id', $careerId)
                ->with('career')
                ->orderBy('ciclo')
                ->orderBy('orden_en_ciclo');
            $subjectsQuery->whereIn('id', $settingsBySubject->keys());
            $subjects = $subjectsQuery->get();
            if ($subjects->isEmpty()) {
                throw ValidationException::withMessages([
                    'period_id' => 'No hay materias activas seleccionadas en la malla de esta carrera.',
                ]);
            }
            if ($subjects->count() !== $settingsBySubject->count()) {
                throw ValidationException::withMessages([
                    'subjects' => 'Todas las materias seleccionadas deben estar activas y pertenecer a la malla de esta carrera.',
                ]);
            }
            $unpreparedIds = Subject::query()
                ->where('activo', true)
                ->where('carrera_id', $careerId)
                ->whereDoesntHave('parallels', fn ($query) => $query
                    ->where('periodo_academico_id', $period->id))
                ->pluck('id');
            if ($unpreparedIds->diff($settingsBySubject->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'subjects' => 'Incluya todas las materias activas que faltan por programar en este período.',
                ]);
            }

            $teacherIds = collect($data['subjects'])
                ->flatMap(fn (array $setting): array => array_column($setting['parallels'], 'teacher_user_id'))
                ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
                ->unique()
                ->values();
            $teacherRoles = RoleAssignment::query()
                ->effective()
                ->where('carrera_id', $careerId)
                ->where('rol', RoleCode::Teacher->value)
                ->whereIn('usuario_id', $teacherIds)
                ->whereHas('user', fn ($query) => $query->where('activo', true)->whereNotNull('correo_verificado_en'))
                ->get()
                ->keyBy('usuario_id');
            if ($teacherRoles->count() !== $teacherIds->count()) {
                throw ValidationException::withMessages([
                    'subjects' => 'Todos los docentes elegidos deben tener una cuenta verificada y un rol docente vigente en esta carrera.',
                ]);
            }

            $parallels = 0;
            $assignments = 0;

            foreach ($subjects as $subject) {
                $this->inheritance->modalityFor($subject);
                /** @var array{parallels: list<array{code: string, shift: string, teacher_user_id?: string|null}>} $setting */
                $setting = $settingsBySubject->get($subject->id);
                foreach ($setting['parallels'] as $parallelSetting) {
                    $parallelExists = Parallel::query()
                        ->where('periodo_academico_id', $period->id)
                        ->where('asignatura_id', $subject->id)
                        ->where('codigo', $parallelSetting['code'])
                        ->exists();

                    if ($parallelExists) {
                        continue;
                    }

                    $parallel = Parallel::query()->create([
                        'periodo_academico_id' => $period->id,
                        'asignatura_id' => $subject->id,
                        'codigo' => $parallelSetting['code'],
                        'jornada' => $parallelSetting['shift'],
                    ]);
                    $this->audit->execute(
                        actorId: $actor->id,
                        roleAssignmentId: $activeRole->id,
                        action: 'academico.paralelo.creacion',
                        resourceType: 'paralelo',
                        resourceId: $parallel->id,
                        result: 'exito',
                        metadata: ['period_prepared' => true, 'bulk' => true],
                    );
                    $parallels++;
                    $teacherId = $parallelSetting['teacher_user_id'] ?? null;
                    if ($teacherId !== null) {
                        $assignment = TeacherAssignment::query()->create([
                            'asignacion_rol_id' => $teacherRoles->get($teacherId)->id,
                            'paralelo_id' => $parallel->id,
                            'activo' => true,
                        ]);
                        $this->audit->execute(
                            actorId: $actor->id,
                            roleAssignmentId: $activeRole->id,
                            action: 'academico.asignacion_docente.creacion',
                            resourceType: 'asignacion_docente',
                            resourceId: $assignment->id,
                            result: 'exito',
                            metadata: ['period_prepared' => true, 'bulk' => true],
                        );
                        $assignments++;
                    }
                }
            }

            return ['parallels' => $parallels, 'assignments' => $assignments, 'subjects' => $subjects->count()];
        });
    }
}
