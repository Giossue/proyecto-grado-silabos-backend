<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodPlanning;
use App\Modules\Academic\Application\ScheduledSubjectInheritance;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\CurriculumSystemFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UpdateCareerAcademicRecord
{
    /** @var array<string, string> */
    private const FIELD_LABELS = [
        'codigo_asignatura' => 'Código de asignatura',
        'codigo_paralelo' => 'Código de paralelo',
        'nombre' => 'Nombre',
        'ciclo' => 'Ciclo',
        'orden_en_ciclo' => 'Orden dentro del ciclo',
        'unidad_organizacion_curricular' => 'Unidad de organización curricular',
        'creditos' => 'Créditos',
        'horas_totales' => 'Horas totales',
        'horas_docencia' => 'Horas AC',
        'horas_practicas' => 'Horas PAE',
        'horas_autonomas' => 'Horas AA',
        'periodo_academico_id' => 'Periodo académico',
        'asignatura_id' => 'Materia',
        'modalidad' => 'Modalidad',
        'usuario_id' => 'Docente',
        'paralelo_id' => 'Paralelo',
    ];

    /** @var list<string> Campos de materia que solo cambian cómo se presenta la ficha. */
    private const PRESENTATION_SUBJECT_FIELDS = [
        'codigo_asignatura', 'nombre', 'ciclo', 'orden_en_ciclo', 'unidad_organizacion_curricular',
    ];

    /** @var array<string, string> */
    private const AUDIT_KEYS = [
        'codigo' => 'code',
        'codigo_asignatura' => 'code',
        'codigo_paralelo' => 'code',
        'nombre' => 'name',
        'ciclo' => 'cycle',
        'orden_en_ciclo' => 'position',
        'unidad_organizacion_curricular' => 'organization_unit',
        'modalidad' => 'modality',
        'creditos' => 'credits',
        'horas_totales' => 'total_hours',
        'horas_docencia' => 'horas_ac',
        'horas_practicas' => 'horas_pae',
        'horas_autonomas' => 'horas_aa',
    ];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
        private readonly ScheduledSubjectInheritance $inheritance,
        private readonly AcademicPeriodPlanning $periodPlanning,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(
        string $entity,
        string $recordId,
        array $data,
        User $actor,
        Request $request,
    ): Model {
        $activeRole = $this->roles->resolve($request);

        if (! $activeRole instanceof RoleAssignment
            || ! AcademicStructurePermissions::mayUpdate($activeRole, $entity)
            || $activeRole->carrera_id === null) {
            throw new AuthorizationException('No puede editar este registro con el rol activo.');
        }
        $this->locks->assertCareerEditable($activeRole->carrera_id);
        $cosmeticSubject = $entity === 'asignatura'
            && $this->onlyPresentationChanges($recordId, $data, $activeRole->carrera_id);
        $careerScope = $entity === 'asignatura' && ! $cosmeticSubject ? $activeRole->carrera_id : [];

        return $this->work->replace($request, function () use ($actor, $activeRole, $cosmeticSubject, $data, $entity, $recordId): Model {
            $record = $this->scopedRecord($entity, $recordId, $activeRole->carrera_id);
            $this->assertPeriodMayChange($record);
            $this->ensureMutable($entity, $record);
            if ($record instanceof Subject && isset($data['cycle'])) {
                $cycleCount = Career::query()
                    ->whereKey($record->carrera_id)
                    ->lockForUpdate()
                    ->value('cantidad_ciclos_malla');
                if ((int) $data['cycle'] > (int) $cycleCount) {
                    throw ValidationException::withMessages([
                        'cycle' => 'El ciclo excede la configuración de esta malla.',
                    ]);
                }
            }
            $attributes = $this->attributes($entity, $data, $activeRole->carrera_id, $record);
            $record->fill($attributes);
            $dirty = $record->getDirty();
            if ($dirty === []) {
                return $record;
            }

            $metadata = $this->auditContext($record, $dirty);
            $record->save();
            $record->refresh();

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: "academico.{$entity}.actualizacion",
                resourceType: $entity,
                resourceId: (string) $record->getKey(),
                result: 'exito',
                metadata: $metadata,
            );
            if ($cosmeticSubject) {
                $this->work->refreshSubjects($activeRole->carrera_id);
            }

            return $record;
        }, $careerScope);
    }

    /** @param array<string, mixed> $data */
    private function onlyPresentationChanges(string $recordId, array $data, string $careerId): bool
    {
        $subject = Subject::query()->whereKey($recordId)->where('carrera_id', $careerId)->first();
        if (! $subject instanceof Subject) {
            return false;
        }
        $subject->fill(array_diff_key(
            $this->subjectAttributes($data, $subject),
            array_flip(self::PRESENTATION_SUBJECT_FIELDS),
        ));

        return ! $subject->isDirty();
    }

    private function scopedRecord(string $entity, string $recordId, string $careerId): Model
    {
        return match ($entity) {
            'asignatura' => Subject::query()
                ->whereKey($recordId)
                ->where('carrera_id', $careerId)
                ->lockForUpdate()->firstOrFail(),
            default => throw new AuthorizationException('El tipo de registro no admite edición desde Coordinación.'),
        };
    }

    private function ensureMutable(string $entity, Model $record): void
    {
        $usedBySyllabus = match ($entity) {
            'asignatura' => false,
            default => true,
        };

        if ($usedBySyllabus) {
            throw ValidationException::withMessages([
                'record' => match ($entity) {
                    default => 'Este registro ya forma parte del historial de un sílabo. No puede modificarse ni eliminarse para conservar la trazabilidad.',
                },
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(string $entity, array $data, string $careerId, Model $record): array
    {
        return match ($entity) {
            'asignatura' => $this->subjectAttributes($data, $record),
            default => throw ValidationException::withMessages(['entity' => 'El tipo de registro no admite edición.']),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function subjectAttributes(array $data, Model $record): array
    {
        if (! $record instanceof Subject) {
            throw new \LogicException('El registro esperado debe ser una materia.');
        }

        $attributes = [
            'codigo_asignatura' => $data['code'],
            'nombre' => $data['nombre'],
            'ciclo' => $data['cycle'] ?? null,
            'modalidad' => $this->inheritance->subjectModality($data),
            'creditos' => $data['creditos'] ?? null,
        ];
        $optional = [
            'position' => 'orden_en_ciclo',
            'organization_unit' => 'unidad_organizacion_curricular',
            'horas_ac' => 'horas_ac',
            'horas_pae' => 'horas_pae',
            'horas_aa' => 'horas_aa',
        ];

        foreach ($optional as $input => $attribute) {
            if (array_key_exists($input, $data)) {
                $attributes[$attribute] = $data[$input];
            }
        }

        return $attributes;
    }

    private function assertPeriodMayChange(Model $record): void
    {
        if ($record instanceof Parallel) {
            $this->periodPlanning->assertParallelMayChange($record);
        }

        if ($record instanceof TeacherAssignment) {
            $this->periodPlanning->assertTeacherAssignmentMayChange($record);
        }
    }

    /**
     * @param  array<string, mixed>  $dirty
     * @return array<string, bool|float|int|string|null>
     */
    private function auditContext(Model $record, array $dirty): array
    {
        $metadata = [
            'changed_fields' => implode(', ', array_map(
                fn (string $field): string => self::FIELD_LABELS[$field] ?? $field,
                array_keys($dirty),
            )),
        ];

        foreach (array_keys($dirty) as $field) {
            $auditKey = self::AUDIT_KEYS[$field] ?? $field;
            $metadata["before_{$auditKey}"] = $this->scalarValue($record->getRawOriginal($field));
            $metadata["after_{$auditKey}"] = $this->scalarValue($record->getAttribute($field));
        }
        if ($record instanceof Subject && array_intersect(array_map($record->mappedColumn(...), CurriculumSystemFields::TOTAL_HOUR_COMPONENT_KEYS), array_keys($dirty)) !== []) {
            $after = (int) round(CurriculumSystemFields::totalHours($record->only(CurriculumSystemFields::TOTAL_HOUR_COMPONENT_KEYS)));
            if ($after !== $record->horas_totales) {
                $metadata['changed_fields'] .= ', '.self::FIELD_LABELS['horas_totales'];
                $metadata['before_total_hours'] = $record->horas_totales;
                $metadata['after_total_hours'] = $after;
            }
        }

        return $metadata;
    }

    private function scalarValue(mixed $value): bool|float|int|string|null
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_bool($value) || is_float($value) || is_int($value) || is_string($value)
            ? $value
            : null;
    }
}
