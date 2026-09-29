<?php

namespace App\Modules\Academic\Application\Queries;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodPlanning;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\CurricularUnit;
use App\Modules\Academic\Domain\CurriculumSystemFields;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectRequirement;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;

class AcademicStructureViewData
{
    public function __construct(
        private readonly ProcessLocks $locks,
        private readonly InstitutionalLogos $logos,
        private readonly AcademicPeriodPlanning $periodPlanning,
    ) {}

    /** @return array<string, mixed> */
    public function governance(): array
    {
        $processesByPeriod = SyllabusProcess::query()
            ->get(['id', 'periodo_academico_id', 'estado'])
            ->keyBy('periodo_academico_id');
        $lockedPeriods = $this->locks->lockedPeriodReasons();

        return [
            'lock_reason' => $lockedPeriods === [] ? null : reset($lockedPeriods),
            'catalogs' => [
                'faculties' => Faculty::query()
                    ->with('logoObject')
                    ->orderBy('nombre')
                    ->get(['id', 'nombre', 'campus_facultad', 'logo_objeto_id', 'activo'])
                    ->map(fn (Faculty $faculty): array => [
                        'id' => $faculty->id,
                        'nombre' => $faculty->nombre,
                        'campus' => $faculty->campus->value,
                        'activo' => $faculty->activo,
                        'logo_url' => $this->logos->facultyIsConfigured($faculty)
                            ? route('logos.faculty', ['faculty' => $faculty->id, 'v' => $this->logos->version($this->logos->facultyPath($faculty))])
                            : null,
                    ]),
                'careers' => Career::query()
                    ->with(['faculty:id,campus_facultad', 'coordinatorAssignments' => fn ($query) => $query->effective()->with('user:id,nombre,correo_electronico')])
                    ->orderBy('nombre')
                    ->get(['id', 'facultad_id', 'modalidad', 'codigo_carrera', 'nombre', 'activo'])
                    ->map(fn (Career $career) => [
                        'id' => $career->id,
                        'faculty_id' => $career->facultad_id,
                        'coordinator' => $career->coordinatorAssignments->first()?->user === null ? null : [
                            'id' => $career->coordinatorAssignments->first()->user->id,
                            'name' => $career->coordinatorAssignments->first()->user->nombre,
                        ],
                        'modality' => $career->modalidad?->value,
                        'modality_label' => $career->modalidad?->label(),
                        'campus_name' => $career->faculty?->campus->value,
                        'code' => $career->codigo_carrera,
                        'name' => $career->nombre,
                        'active' => $career->activo,
                    ]),
                'periods' => AcademicPeriod::query()
                    ->orderByDesc('fecha_inicio')
                    ->get()
                    ->map(function (AcademicPeriod $period) use ($lockedPeriods, $processesByPeriod): array {
                        $process = $processesByPeriod->get($period->id);

                        return [
                            'id' => $period->id,
                            'code' => $period->codigo,
                            'starts_on' => $period->fecha_inicio->toDateString(),
                            'ends_on' => $period->fecha_fin->toDateString(),
                            'teaching_weeks' => $period->semanas_lectivas,
                            'status' => $this->periodPlanning->status($period),
                            'status_label' => $this->periodPlanning->label($period),
                            'institutional_process' => $process instanceof SyllabusProcess ? [
                                'id' => $process->id,
                                'state' => $process->estado,
                            ] : null,
                            'lock_reason' => $lockedPeriods[$period->id] ?? null,
                        ];
                    }),
            ],
            'options' => [
                ...$this->emptyOptions(),
                'faculties' => Faculty::query()
                    ->where('activo', true)
                    ->orderBy('nombre')
                    ->get(['id', 'nombre']),
                'campusOptions' => CampusLocation::options(),
                'coordinatorUsers' => User::query()
                    ->where('activo', true)
                    ->whereNotIn('id', RoleAssignment::query()
                        ->select('usuario_id')
                        ->effective()
                        ->where('rol', RoleCode::Administrator->value))
                    ->orderBy('nombre')
                    ->get(['id', 'nombre', 'correo_electronico']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function curricula(string $careerId): array
    {
        $career = $this->career($careerId);

        return [
            'career' => ['id' => $career->id, 'name' => $career->nombre],
            'options' => $this->emptyOptions(),
        ];
    }

    public function currentCurriculumId(string $careerId): ?string
    {
        $id = Career::query()->where('activo', true)->whereKey($careerId)->value('id');

        return is_string($id) ? $id : null;
    }

    /** @return array<string, mixed> */
    public function curriculumBuilder(string $careerId, string $curriculumId): array
    {
        $career = $this->career($careerId);
        abort_unless($curriculumId === $careerId, 404);
        $career = Career::query()
            ->whereKey($careerId)
            ->with(['subjects' => fn ($query) => $query
                ->orderBy('ciclo')
                ->orderBy('orden_en_ciclo')
                ->orderBy('nombre')])
            ->firstOrFail();
        $definitions = collect(CurriculumSystemFields::fixedDefinitions());
        $subjectIds = $career->subjects->pluck('id');
        $lockReason = $this->locks->careerLockReason($careerId);

        return [
            'career' => [
                'id' => $career->id,
                'name' => $career->nombre,
                'modality' => $career->modalidad === null ? null : [
                    'value' => $career->modalidad->value,
                    'label' => $career->modalidad->label(),
                ],
            ],
            'curriculum' => [
                'id' => $career->id,
                'cycle_count' => $career->cantidad_ciclos_malla,
                'editable' => $lockReason === null,
                'lock_reason' => $lockReason,
            ],
            'fixedFields' => $definitions->map(fn (array $field) => [
                'id' => $field['system_key'],
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'system_key' => $field['system_key'],
                'system_label' => CurriculumSystemFields::LABELS[$field['system_key']] ?? $field['label'],
                'position' => $field['position'],
                'visible_on_card' => true,
                'totalizable' => $field['totalizable'],
            ])->values(),
            'fixedFieldTotals' => $definitions
                ->where('totalizable', true)
                ->map(function (array $field) use ($career): array {
                    $value = $career->subjects->sum(fn (Subject $subject): float|int => is_numeric(CurriculumSystemFields::value($subject, $field['system_key']))
                            ? CurriculumSystemFields::value($subject, $field['system_key']) + 0
                            : 0);

                    return [
                        'id' => $field['system_key'],
                        'label' => $field['label'],
                        'value' => $value,
                    ];
                })->values(),
            'subjects' => $career->subjects->map(function (Subject $subject) use ($definitions): array {
                return [
                    'id' => $subject->id,
                    'code' => $subject->codigo_asignatura,
                    'name' => $subject->nombre,
                    'cycle' => $subject->ciclo,
                    'position' => $subject->orden_en_ciclo,
                    'organization_unit' => $subject->unidad_organizacion_curricular?->value,
                    'organization_unit_label' => $subject->unidad_organizacion_curricular?->label(),
                    'modality' => $subject->modalidad?->value,
                    'modality_label' => $subject->modalidad?->label(),
                    'credits' => $subject->creditos,
                    'total_hours' => $subject->horas_totales,
                    'active' => $subject->activo,
                    'system_values' => collect(CurriculumSystemFields::ATTRIBUTES)->mapWithKeys(
                        fn (string $attribute, string $key) => [$key => $subject->getAttribute($attribute)],
                    ),
                    'display_fields' => $definitions
                        ->map(fn (array $field) => [
                            'id' => $field['system_key'],
                            'label' => $field['label'],
                            'value' => CurriculumSystemFields::value($subject, $field['system_key']),
                        ])->values(),
                ];
            })->values(),
            'requirements' => SubjectRequirement::query()
                ->whereIn('asignatura_id', $subjectIds)
                ->whereIn('requisito_id', $subjectIds)
                ->orderBy('tipo')
                ->orderBy('id')
                ->get(['id', 'asignatura_id', 'requisito_id', 'tipo'])
                ->map(fn (SubjectRequirement $requirement) => [
                    'id' => $requirement->id,
                    'subject_id' => $requirement->asignatura_id,
                    'requirement_id' => $requirement->requisito_id,
                    'type' => $requirement->tipo,
                ]),
            'modalityOptions' => StudyModality::options(),
            'organizationUnitOptions' => CurricularUnit::options(),
            'options' => $this->emptyOptions(),
        ];
    }

    /** @return array<string, mixed> */
    public function scheduledSubjects(string $careerId, ?string $requestedPeriodId = null): array
    {
        $career = $this->career($careerId);
        $lockReason = $this->locks->careerLockReason($careerId);
        $scheduledSubjects = ScheduledSubject::query()
            ->whereHas('subject', fn ($query) => $query->where('carrera_id', $careerId))
            ->with([
                'academicPeriod:id,fecha_inicio,fecha_fin',
                'subject:id,nombre,codigo_asignatura,ciclo',
                'parallels' => fn ($query) => $query
                    ->orderBy('codigo')
                    ->select(['id', 'programacion_asignatura_id', 'codigo', 'jornada']),
            ])
            ->orderBy('asignatura_id')
            ->get();
        $usedScheduledSubjectIds = Parallel::query()
            ->whereIn('programacion_asignatura_id', $scheduledSubjects->pluck('id'))
            ->whereIn('id', Syllabus::query()->select('paralelo_id'))
            ->pluck('programacion_asignatura_id')
            ->flip();
        $parallelIds = $scheduledSubjects->flatMap(fn (ScheduledSubject $item) => $item->parallels->pluck('id'));
        $parallelsWithSyllabus = Syllabus::query()->whereIn('paralelo_id', $parallelIds)->pluck('paralelo_id')->flip();
        $parallelsWithTeacher = TeacherAssignment::query()->whereIn('paralelo_id', $parallelIds)->pluck('paralelo_id')->flip();
        $periods = AcademicPeriod::query()
            ->get(['id', 'codigo', 'fecha_inicio', 'fecha_fin'])
            ->sort(function (AcademicPeriod $left, AcademicPeriod $right): int {
                $rank = [
                    AcademicPeriodPlanning::CURRENT => 0,
                    AcademicPeriodPlanning::UPCOMING => 1,
                    AcademicPeriodPlanning::FINISHED => 2,
                ];
                $leftStatus = $this->periodPlanning->status($left);
                $rightStatus = $this->periodPlanning->status($right);
                $byStatus = $rank[$leftStatus] <=> $rank[$rightStatus];

                if ($byStatus !== 0) {
                    return $byStatus;
                }

                return $leftStatus === AcademicPeriodPlanning::UPCOMING
                    ? $left->fecha_inicio <=> $right->fecha_inicio
                    : $right->fecha_inicio <=> $left->fecha_inicio;
            })
            ->values();
        $selectedPeriodId = $periods->contains('id', $requestedPeriodId)
            ? $requestedPeriodId
            : $periods->first(fn (AcademicPeriod $period): bool => $this->periodPlanning->status($period) === AcademicPeriodPlanning::CURRENT)?->id;
        $selectedPeriodId ??= $periods->first(fn (AcademicPeriod $period): bool => $this->periodPlanning->status($period) === AcademicPeriodPlanning::UPCOMING)?->id;
        $selectedPeriodId ??= $periods->first()?->id;

        return [
            'career' => [
                'id' => $career->id,
                'name' => $career->nombre,
                'lock_reason' => $lockReason,
            ],
            'selectedPeriodId' => $selectedPeriodId,
            'scheduledSubjects' => $scheduledSubjects
                ->map(function (ScheduledSubject $scheduledSubject) use ($lockReason, $usedScheduledSubjectIds, $parallelsWithSyllabus, $parallelsWithTeacher): array {
                    $periodPlanningEnabled = $this->periodPlanning->mayPlan($scheduledSubject->academicPeriod);

                    return [
                        'id' => $scheduledSubject->id,
                        'subject_id' => $scheduledSubject->asignatura_id,
                        'period_id' => $scheduledSubject->periodo_academico_id,
                        'label' => "{$scheduledSubject->subject->codigo_asignatura} · {$scheduledSubject->subject->nombre}",
                        'subject_code' => $scheduledSubject->subject->codigo_asignatura,
                        'subject_name' => $scheduledSubject->subject->nombre,
                        'subject_cycle' => $scheduledSubject->subject->ciclo,
                        'period_starts_on' => $scheduledSubject->academicPeriod->fecha_inicio->toDateString(),
                        'period_ends_on' => $scheduledSubject->academicPeriod->fecha_fin->toDateString(),
                        'period_status' => $this->periodPlanning->status($scheduledSubject->academicPeriod),
                        'period_status_label' => $this->periodPlanning->label($scheduledSubject->academicPeriod),
                        'period_planning_enabled' => $periodPlanningEnabled,
                        // Derivados de la facultad y la carrera; pueden faltar si la malla se retiró.
                        'campus_name' => $scheduledSubject->campus_programacion,
                        'modality_name' => $scheduledSubject->modalidad?->label(),
                        'parallels' => $scheduledSubject->parallels
                            ->map(fn (Parallel $parallel): array => [
                                'id' => $parallel->id,
                                'code' => $parallel->codigo,
                                'shift' => $this->parallelShift($parallel),
                                'editable' => ! $parallelsWithSyllabus->has($parallel->id),
                                'deletable' => ! $parallelsWithSyllabus->has($parallel->id) && ! $parallelsWithTeacher->has($parallel->id),
                            ])
                            ->values()
                            ->all(),
                        'active' => $scheduledSubject->activo,
                        'editable' => $periodPlanningEnabled && $lockReason === null && ! $usedScheduledSubjectIds->has($scheduledSubject->id),
                    ];
                }),
            'options' => [
                ...$this->emptyOptions(),
                'periods' => $periods
                    ->map(fn (AcademicPeriod $period): array => [
                        'id' => $period->id,
                        'code' => $period->codigo,
                        'starts_on' => $period->fecha_inicio->toDateString(),
                        'ends_on' => $period->fecha_fin->toDateString(),
                        'status' => $this->periodPlanning->status($period),
                        'status_label' => $this->periodPlanning->label($period),
                        'planning_enabled' => $this->periodPlanning->mayPlan($period),
                    ]),
                'activeSubjects' => Subject::query()
                    ->where('activo', true)
                    ->where('carrera_id', $careerId)
                    ->orderBy('ciclo')
                    ->orderBy('orden_en_ciclo')
                    ->orderBy('nombre')
                    ->get(['id', 'codigo_asignatura', 'nombre', 'ciclo']),
                'scheduledSubjects' => ScheduledSubject::query()
                    ->where('activo', true)
                    ->whereHas('subject', fn ($query) => $query->where('carrera_id', $careerId))
                    ->with(['subject:id,codigo_asignatura,nombre', 'academicPeriod:id,codigo,fecha_inicio,fecha_fin'])
                    ->get()
                    ->filter(fn (ScheduledSubject $scheduledSubject): bool => $this->periodPlanning->mayPlan($scheduledSubject->academicPeriod))
                    ->map(fn (ScheduledSubject $scheduledSubject) => [
                        'id' => $scheduledSubject->id,
                        'label' => "{$scheduledSubject->subject->codigo_asignatura} · {$scheduledSubject->academicPeriod->codigo}",
                        'subject_id' => $scheduledSubject->asignatura_id,
                        'period_id' => $scheduledSubject->periodo_academico_id,
                    ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function teacherAssignments(string $careerId): array
    {
        $career = $this->career($careerId);
        $lockReason = $this->locks->careerLockReason($careerId);
        $teacherAssignments = TeacherAssignment::query()
            ->whereHas(
                'parallel.scheduledSubject.subject',
                fn ($query) => $query->where('carrera_id', $careerId),
            )
            ->with([
                'roleAssignment.user:id,nombre,correo_electronico',
                'parallel.scheduledSubject.subject:id,nombre,codigo_asignatura',
                'parallel.scheduledSubject.academicPeriod:id,codigo,fecha_inicio,fecha_fin',
            ])
            ->orderByDesc('asignado_en')
            ->get();

        return [
            'career' => [
                'id' => $career->id,
                'name' => $career->nombre,
                'lock_reason' => $lockReason,
            ],
            'teacherAssignments' => $teacherAssignments
                ->map(fn (TeacherAssignment $assignment) => [
                    'id' => $assignment->id,
                    'user_id' => $assignment->roleAssignment->user->id,
                    'parallel_id' => $assignment->paralelo_id,
                    'user_name' => $assignment->roleAssignment->user->nombre,
                    'user_email' => $assignment->roleAssignment->user->correo_electronico,
                    'parallel_code' => $assignment->parallel->codigo,
                    'subject_name' => $assignment->parallel->scheduledSubject->subject->nombre,
                    'period_name' => $assignment->parallel->scheduledSubject->academicPeriod->codigo,
                    'period_status' => $this->periodPlanning->status($assignment->parallel->scheduledSubject->academicPeriod),
                    'period_planning_enabled' => $this->periodPlanning->mayPlan($assignment->parallel->scheduledSubject->academicPeriod),
                    'active' => $assignment->activo,
                    'editable' => $this->periodPlanning->mayPlan($assignment->parallel->scheduledSubject->academicPeriod),
                ]),
            'careerTeachers' => RoleAssignment::query()
                ->effective()
                ->where('carrera_id', $careerId)
                ->where('rol', RoleCode::Teacher->value)
                ->whereHas('user', fn ($query) => $query->where('activo', true))
                ->with('user:id,nombre,correo_electronico,correo_verificado_en')
                ->get()
                ->map(fn (RoleAssignment $assignment): array => [
                    'id' => $assignment->user->id,
                    'name' => $assignment->user->nombre,
                    'email' => $assignment->user->correo_electronico,
                    'pending_activation' => $assignment->user->correo_verificado_en === null,
                ])
                ->unique('id')
                ->sortBy('name')
                ->values(),
            'pendingTeachers' => RoleAssignment::query()
                ->effective()
                ->where('carrera_id', $careerId)
                ->where('rol', RoleCode::Teacher->value)
                ->whereHas('user', fn ($query) => $query
                    ->where('activo', true)
                    ->whereNull('correo_verificado_en'))
                ->with('user:id,nombre,correo_electronico')
                ->get()
                ->map(fn (RoleAssignment $assignment): array => [
                    'id' => $assignment->user->id,
                    'name' => $assignment->user->nombre,
                    'email' => $assignment->user->correo_electronico,
                ])
                ->unique('id')
                ->values(),
            'options' => [
                ...$this->emptyOptions(),
                'parallels' => Parallel::query()
                    ->whereHas(
                        'scheduledSubject.subject',
                        fn ($query) => $query->where('carrera_id', $careerId),
                    )
                    ->whereDoesntHave('teacherAssignments', fn ($query) => $query->where('activo', true))
                    ->with([
                        'scheduledSubject.subject:id,codigo_asignatura,nombre',
                        'scheduledSubject.academicPeriod:id,codigo,fecha_inicio,fecha_fin',
                    ])
                    ->get()
                    ->filter(fn (Parallel $parallel): bool => $this->periodPlanning->mayPlan($parallel->scheduledSubject->academicPeriod))
                    ->map(fn (Parallel $parallel) => [
                        'id' => $parallel->id,
                        'label' => "{$parallel->scheduledSubject->subject->nombre} · {$parallel->scheduledSubject->academicPeriod->codigo} · Paralelo {$parallel->codigo}",
                    ]),
                'teacherUsers' => User::query()
                    ->where('activo', true)
                    ->whereNotNull('correo_verificado_en')
                    ->whereIn('id', RoleAssignment::query()
                        ->select('usuario_id')
                        ->effective()
                        ->where('carrera_id', $careerId)
                        ->where('rol', RoleCode::Teacher->value))
                    ->orderBy('nombre')
                    ->get(['id', 'nombre', 'correo_electronico'])
                    ->map(fn (User $user): array => [
                        'id' => $user->id,
                        'name' => $user->nombre,
                        'email' => $user->correo_electronico,
                    ]),
            ],
        ];
    }

    private function career(string $careerId): Career
    {
        return Career::query()->where('activo', true)->findOrFail($careerId);
    }

    private function parallelShift(Parallel $parallel): ?string
    {
        $shift = $parallel->getAttribute('jornada');

        return is_string($shift) ? $shift : null;
    }

    /** @return array<string, array<never, never>> */
    private function emptyOptions(): array
    {
        return [
            'faculties' => [],
            'careers' => [],
            'periods' => [],
            'campusOptions' => [],
            'currentCurricula' => [],
            'activeSubjects' => [],
            'scheduledSubjects' => [],
            'parallels' => [],
            'coordinatorUsers' => [],
            'teacherUsers' => [],
        ];
    }
}
