<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodLabel;
use App\Modules\Academic\Application\AcademicPeriodWeeks;
use App\Modules\Academic\Application\ScheduledSubjectInheritance;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Domain\RoleCombination;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\FinalizedChangeNotice;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CreateAcademicRecord
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
        private readonly InstitutionalLogos $logos,
        private readonly ScheduledSubjectInheritance $inheritance,
        private readonly AcademicPeriodLabel $periodLabel,
        private readonly AcademicPeriodWeeks $periodWeeks,
        private readonly FinalizedChangeNotice $notice,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(string $entity, array $data, User $actor, Request $request): Model
    {
        $activeRole = $this->roles->resolve($request);

        if (! $activeRole instanceof RoleAssignment || ! AcademicStructurePermissions::mayCreate($activeRole, $entity)) {
            throw new AuthorizationException('No puede gestionar este tipo de registro con el rol activo.');
        }
        if (AcademicStructurePermissions::isCareerContext($activeRole) && $entity !== 'asignacion_docente') {
            $this->locks->assertCareerEditable($activeRole->carrera_id);
        }

        $create = fn (): Model => $this->work->replace($request, function () use ($actor, $activeRole, $data, $entity): Model {
            $record = $this->create($entity, $data, $activeRole);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: "academico.{$entity}.creacion",
                resourceType: $entity,
                resourceId: (string) $record->getKey(),
                result: 'exito',
            );

            return $record;
        }, []);

        return $entity === 'asignacion_docente'
            ? $this->notice->guard($request, $activeRole->carrera_id, $create, $this->subjectOf($data))
            : $create();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function subjectOf(array $data): array
    {
        return array_values(array_map('strval', Parallel::query()->whereKey($this->stringValue($data, 'parallel_id'))->pluck('asignatura_id')->all()));
    }

    /** @param array<string, mixed> $data */
    private function createFaculty(array $data): Faculty
    {
        $faculty = Faculty::query()->create([
            'nombre' => $data['nombre'],
            'campus' => CampusLocation::from($data['campus']),
            'koha_libraries' => array_values($data['catalog_libraries'] ?? []),
            'activo' => true,
        ]);
        if (($data['logo'] ?? null) instanceof UploadedFile) {
            $this->logos->storeFaculty($faculty, $data['logo']);
        }

        return $faculty;
    }

    /** @param array<string, mixed> $data */
    private function create(string $entity, array $data, RoleAssignment $activeRole): Model
    {
        return match ($entity) {
            'facultad' => $this->createFaculty($data),
            'carrera' => Career::query()->create([
                'facultad_id' => $data['faculty_id'],
                'modalidad' => $data['modality'],
                'codigo_carrera' => $data['code'],
                'nombre' => $data['nombre'],
                'activo' => true,
                'cantidad_ciclos_malla' => $data['cycle_count'],
            ]),
            'periodo' => AcademicPeriod::query()->create([
                'codigo' => $this->periodLabel->make($data['starts_on'], $data['ends_on']),
                'fecha_inicio' => $data['starts_on'],
                'fecha_fin' => $data['ends_on'],
                'semanas_lectivas' => $data['teaching_weeks'] ?? $this->periodWeeks->calculate($data['starts_on'], $data['ends_on']),
            ]),
            'asignatura' => $this->createSubject($data, $this->careerId($activeRole)),
            'asignacion_docente' => $this->createTeacherAssignment($data, $this->careerId($activeRole)),
            default => throw ValidationException::withMessages([
                'entity' => 'El tipo de registro académico no es válido.',
            ]),
        };
    }

    /** @param array<string, mixed> $data */
    private function createSubject(array $data, string $careerId): Subject
    {
        $career = Career::query()->whereKey($careerId)->lockForUpdate()->firstOrFail();

        if (isset($data['cycle']) && (int) $data['cycle'] > $career->cantidad_ciclos_malla) {
            throw ValidationException::withMessages([
                'cycle' => 'El ciclo excede la configuración de esta malla.',
            ]);
        }

        $lastPosition = Subject::query()
            ->where('carrera_id', $career->id)
            ->where('ciclo', $data['cycle'])
            ->max('orden_en_ciclo');
        $position = array_key_exists('position', $data)
            ? (int) $data['position']
            : ($lastPosition === null ? 0 : (int) $lastPosition + 1);

        $subject = Subject::query()->create([
            'carrera_id' => $career->id,
            'codigo_asignatura' => $data['code'],
            'nombre' => $data['nombre'],
            'ciclo' => $data['cycle'] ?? null,
            'orden_en_ciclo' => $position,
            'unidad_organizacion_curricular' => $data['organization_unit'] ?? null,
            'modalidad' => $this->inheritance->subjectModality($data),
            'creditos' => $data['creditos'] ?? null,
            'horas_ac' => $data['horas_ac'] ?? null,
            'horas_pae' => $data['horas_pae'] ?? null,
            'horas_aa' => $data['horas_aa'] ?? null,
            'activo' => true,
            'genera_silabo' => filter_var($data['generates_syllabus'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ]);

        return $subject->refresh();
    }

    /** @param array<string, mixed> $data */
    private function createTeacherAssignment(array $data, string $careerId): TeacherAssignment
    {
        $parallel = Parallel::query()
            ->with('subject.career:id')
            ->whereKey($this->stringValue($data, 'parallel_id'))
            ->whereHas(
                'subject',
                fn ($query) => $query->where('carrera_id', $careerId),
            )
            ->lockForUpdate()
            ->firstOrFail();
        $userId = $this->stringValue($data, 'user_id');
        $roleAssignment = $this->teacherRole($userId, $careerId);

        $alreadyAssigned = TeacherAssignment::query()
            ->where('paralelo_id', $parallel->id)
            ->where('activo', true)
            ->exists();

        if ($alreadyAssigned) {
            throw ValidationException::withMessages([
                'user_id' => 'El paralelo ya tiene un docente asignado.',
            ]);
        }

        $assignment = TeacherAssignment::query()->create([
            'asignacion_rol_id' => $roleAssignment->id,
            'paralelo_id' => $parallel->id,
            'activo' => true,
        ]);
        $this->work->restartForParallel($parallel->id);

        return $assignment;
    }

    private function careerId(RoleAssignment $activeRole): string
    {
        if (! AcademicStructurePermissions::isCareerContext($activeRole) || $activeRole->carrera_id === null) {
            throw new AuthorizationException('Seleccione una coordinación activa con carrera.');
        }

        return $activeRole->carrera_id;
    }

    private function teacherRole(string $userId, string $careerId): RoleAssignment
    {
        $identity = ['usuario_id' => $userId, 'carrera_id' => $careerId, 'rol' => RoleCode::Teacher->value];
        $isCoordinator = RoleAssignment::query()->effective()
            ->where(['usuario_id' => $userId, 'carrera_id' => $careerId, 'rol' => RoleCode::Coordinator->value])
            ->exists();

        if ($isCoordinator && RoleCombination::conflicts(RoleCode::Teacher, RoleAssignment::query()->effective()->where('usuario_id', $userId)->pluck('rol'))) {
            throw ValidationException::withMessages(['user_id' => RoleCombination::MESSAGE]);
        }

        if ($isCoordinator) {
            $assignment = RoleAssignment::query()->where($identity)->orderByDesc('activo')->first();
            if ($assignment === null) {
                RoleAssignment::query()->create([...$identity, 'activo' => true]);
            } elseif (! $assignment->activo) {
                $assignment->update(['activo' => true]);
            }
        }

        return $this->scopedRole($userId, $careerId, RoleCode::Teacher);
    }

    private function scopedRole(string $userId, string $careerId, RoleCode $role): RoleAssignment
    {
        $assignment = RoleAssignment::query()
            ->effective()
            ->where('usuario_id', $userId)
            ->where('carrera_id', $careerId)
            ->where('rol', $role->value)
            ->whereHas('user', fn ($query) => $query
                ->where('activo', true)
                ->whereNotNull('correo_verificado_en'))
            ->first();

        if (! $assignment instanceof RoleAssignment) {
            throw ValidationException::withMessages([
                'user_id' => 'El docente debe verificar su correo antes de recibir una asignación.',
            ]);
        }

        return $assignment;
    }

    /** @param array<string, mixed> $data */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw ValidationException::withMessages([$key => 'El identificador recibido no es válido.']);
        }

        return $value;
    }
}
