<?php

namespace App\Modules\Academic\Application\Queries;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodPlanning;
use App\Modules\Academic\Application\CatalogDeactivationBlocks;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\CurricularUnit;
use App\Modules\Academic\Domain\CurriculumSystemFields;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectRequirement;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class AcademicStructureViewData
{
    public function __construct(
        private readonly ProcessLocks $locks,
        private readonly InstitutionalLogos $logos,
        private readonly AcademicPeriodPlanning $periodPlanning,
        private readonly SubjectProfileFields $profiles,
        private readonly CatalogDeactivationBlocks $deactivationBlocks,
    ) {}

    /** @return array<string, mixed> */
    public function governance(): array
    {
        $processesByPeriod = SyllabusProcess::query()
            ->get(['id', 'periodo_academico_id', 'estado'])
            ->keyBy('periodo_academico_id');
        $lockedPeriods = $this->locks->lockedPeriodReasons();
        $facultyBlocks = $this->deactivationBlocks->forFaculties(Faculty::query()->where('activo', true)->get(['id'])->map(fn (Faculty $faculty): string => $faculty->id)->values()->all());
        $careerBlocks = $this->deactivationBlocks->forCareers(Career::query()->where('activo', true)->get(['id'])->map(fn (Career $career): string => $career->id)->values()->all());

        return [
            'lock_reason' => $lockedPeriods === [] ? null : reset($lockedPeriods),
            'catalogs' => [
                'faculties' => Faculty::query()
                    ->with('logoObject')
                    ->orderBy('nombre')
                    ->get(['id', 'nombre', 'campus_facultad', 'bibliotecas_koha_facultad', 'logo_archivo_id', 'activo'])
                    ->map(fn (Faculty $faculty): array => [
                        'id' => $faculty->id,
                        'nombre' => $faculty->nombre,
                        'campus' => $faculty->campus->value,
                        'catalog_libraries' => $faculty->koha_libraries,
                        'activo' => $faculty->activo,
                        'deactivate_block' => $facultyBlocks[$faculty->id] ?? null,
                        'lock_reason' => null,
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
                        'deactivate_block' => $careerBlocks[$career->id] ?? null,
                        'lock_reason' => null,
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
                ...$this->baseOptions(),
                'faculties' => Faculty::query()
                    ->where('activo', true)
                    ->orderBy('nombre')
                    ->get(['id', 'nombre']),
                'campusOptions' => CampusLocation::options(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function curricula(string $careerId): array
    {
        $career = $this->career($careerId);

        return [
            'career' => ['id' => $career->id, 'name' => $career->nombre],
            'options' => $this->baseOptions(),
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
                'lock' => $lockReason === null ? null : $this->locks->careerLock($careerId),
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
                    'generates_syllabus' => $subject->genera_silabo,
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
            'options' => $this->baseOptions(),
        ];
    }

    /** @return array<string, mixed> */
    public function scheduledSubjects(string $careerId): array
    {
        $career = $this->career($careerId);
        $lockReason = $this->locks->careerLockReason($careerId);
        $careerParallels = Parallel::query()
            ->whereHas('subject', fn ($query) => $query->where('carrera_id', $careerId))
            ->orderBy('codigo')
            ->get(['id', 'asignatura_id', 'codigo', 'jornada']);
        $parallelsBySubject = $careerParallels->groupBy('asignatura_id');
        $parallelIds = $careerParallels->pluck('id');
        $parallelsWithSyllabus = Syllabus::query()->whereIn('paralelo_id', $parallelIds)->where(fn (Builder $query) => $query->where('estado', '!=', 'sin_iniciar')->orWhereNotNull('iniciado_en'))->pluck('paralelo_id')->flip();
        $parallelsWithTeacher = TeacherAssignment::query()->whereIn('paralelo_id', $parallelIds)->pluck('paralelo_id')->flip();

        $careerSubjects = Subject::query()
            ->where('carrera_id', $careerId)
            ->with(['career.faculty'])
            ->orderBy('ciclo')
            ->orderBy('orden_en_ciclo')
            ->orderBy('nombre')
            ->get(['id', 'carrera_id', 'codigo_asignatura', 'nombre', 'ciclo', 'activo', 'genera_silabo', 'modalidad']);
        $profileComplete = $this->profiles->completeBySubject($careerSubjects->map(fn (Subject $subject): string => $subject->id)->all());
        $rows = [];
        foreach ($careerSubjects as $subject) {
            $group = $parallelsBySubject->get($subject->id, new EloquentCollection);
            if ($group->isEmpty() && ! $subject->activo) {
                continue;
            }

            $rows[] = [
                'subject_id' => $subject->id,
                'label' => "{$subject->codigo_asignatura} · {$subject->nombre}",
                'subject_code' => $subject->codigo_asignatura,
                'subject_name' => $subject->nombre,
                'subject_cycle' => $subject->ciclo,
                'profile_complete' => $profileComplete[$subject->id] ?? false,
                'generates_syllabus' => $subject->genera_silabo,
                'campus_name' => $subject->campusName(),
                'modality_name' => $subject->studyModality()?->label(),
                'parallels' => $group
                    ->map(fn (Parallel $parallel): array => [
                        'id' => $parallel->id,
                        'code' => $parallel->codigo,
                        'shift' => $this->parallelShift($parallel),
                        'editable' => true,
                        'deletable' => ! $parallelsWithSyllabus->has($parallel->id) && ! $parallelsWithTeacher->has($parallel->id),
                    ])
                    ->values()
                    ->all(),
                'editable' => true,
            ];
        }

        $activeSubjects = Subject::query()
            ->where('activo', true)
            ->where('carrera_id', $careerId)
            ->orderBy('ciclo')
            ->orderBy('orden_en_ciclo')
            ->orderBy('nombre')
            ->get(['id', 'codigo_asignatura', 'nombre', 'ciclo', 'genera_silabo']);
        $activeProgress = $this->profiles->progressBySubject($activeSubjects->map(fn (Subject $subject): string => $subject->id)->all());

        return [
            'career' => [
                'id' => $career->id,
                'name' => $career->nombre,
                'lock_reason' => $lockReason,
                'lock' => $lockReason === null ? null : $this->locks->careerLock($careerId),
            ],
            'scheduledSubjects' => $rows,
            'options' => [
                ...$this->baseOptions(),
                'activeSubjects' => $activeSubjects
                    ->map(fn (Subject $subject): array => [
                        'id' => $subject->id,
                        'codigo_asignatura' => $subject->codigo_asignatura,
                        'nombre' => $subject->nombre,
                        'ciclo' => $subject->ciclo,
                        'generates_syllabus' => $subject->genera_silabo,
                        'profile_complete' => ($activeProgress[$subject->id]['filled'] ?? 0) === ($activeProgress[$subject->id]['total'] ?? 0),
                        'profile_filled' => $activeProgress[$subject->id]['filled'] ?? 0,
                        'profile_total' => $activeProgress[$subject->id]['total'] ?? 0,
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
                'parallel.subject',
                fn ($query) => $query->where('carrera_id', $careerId),
            )
            ->with([
                'roleAssignment.user:id,nombre,correo_electronico',
                'parallel.subject:id,nombre,codigo_asignatura',
            ])
            ->orderByDesc('asignado_en')
            ->get();

        return [
            'career' => [
                'id' => $career->id,
                'name' => $career->nombre,
                'lock_reason' => $lockReason,
                'lock' => $lockReason === null ? null : $this->locks->careerLock($careerId),
            ],
            'teacherAssignments' => $teacherAssignments
                ->map(fn (TeacherAssignment $assignment) => [
                    'id' => $assignment->id,
                    'user_id' => $assignment->roleAssignment->user->id,
                    'parallel_id' => $assignment->paralelo_id,
                    'user_name' => $assignment->roleAssignment->user->nombre,
                    'user_email' => $assignment->roleAssignment->user->correo_electronico,
                    'parallel_code' => $assignment->parallel->codigo,
                    'subject_name' => $assignment->parallel->subject->nombre,
                    'active' => $assignment->activo,
                    'editable' => true,
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
                ...$this->baseOptions(),
                'parallels' => Parallel::query()
                    ->whereHas(
                        'subject',
                        fn ($query) => $query->where('carrera_id', $careerId),
                    )
                    ->whereDoesntHave('teacherAssignments', fn ($query) => $query->where('activo', true))
                    ->with('subject:id,codigo_asignatura,nombre')
                    ->get()
                    ->map(fn (Parallel $parallel) => [
                        'id' => $parallel->id,
                        'label' => "{$parallel->subject->nombre} · Paralelo {$parallel->codigo}",
                    ]),
                'teacherUsers' => User::query()
                    ->where('activo', true)
                    ->whereNotNull('correo_verificado_en')
                    ->whereIn('id', RoleAssignment::query()
                        ->select('usuario_id')
                        ->effective()
                        ->where('carrera_id', $careerId)
                        ->whereIn('rol', [RoleCode::Teacher->value, RoleCode::Coordinator->value]))
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

    /** @return array<string, list<array{value: string, label: string}>> */
    private function baseOptions(): array
    {
        return [
            'faculties' => [],
            'careers' => [],
            'periods' => [],
            'campusOptions' => [],
            'modalityOptions' => StudyModality::options(),
            'shiftOptions' => Parallel::shiftOptions(),
            'currentCurricula' => [],
            'activeSubjects' => [],
            'scheduledSubjects' => [],
            'parallels' => [],
            'teacherUsers' => [],
        ];
    }
}
