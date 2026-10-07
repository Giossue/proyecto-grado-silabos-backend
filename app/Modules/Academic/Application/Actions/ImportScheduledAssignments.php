<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodPlanning;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportScheduledAssignments
{
    public const COLUMNS = ['materia' => 'Código de materia', 'paralelo' => 'Paralelo', 'jornada' => 'Jornada', 'docente' => 'Correo del docente'];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly CreateParallels $createParallels,
        private readonly CreateAcademicRecord $createRecord,
        private readonly AcademicPeriodPlanning $planning,
    ) {}

    /** @return array{subjects: list<string>, teachers: list<string>} */
    public function templateOptions(Request $request): array
    {
        $role = $this->roles->resolve($request);
        if (! $role instanceof RoleAssignment || $role->carrera_id === null
            || ! AcademicStructurePermissions::mayCreate($role, 'paralelo')) {
            throw new AuthorizationException('No puede importar con el rol activo.');
        }

        return [
            'subjects' => Subject::query()->where('carrera_id', $role->carrera_id)->where('activo', true)
                ->orderBy('ciclo')->orderBy('orden_en_ciclo')->pluck('codigo_asignatura')->map(fn (mixed $code): string => (string) $code)->all(),
            'teachers' => RoleAssignment::query()->effective()->with('user')
                ->where('carrera_id', $role->carrera_id)->where('rol', RoleCode::Teacher->value)
                ->whereHas('user', fn ($query) => $query->where('activo', true)->whereNotNull('correo_verificado_en'))
                ->get()->map(fn (RoleAssignment $assignment): string => $assignment->user->correo_electronico)->sort()->values()->all(),
        ];
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return array{rows: list<array{line: int, values: array<string, string>, errors: list<string>}>, valid: bool, created: int}
     */
    public function execute(array $rows, string $periodId, User $actor, Request $request, bool $confirm): array
    {
        $role = $this->roles->resolve($request);
        if (! $role instanceof RoleAssignment || $role->carrera_id === null
            || ! AcademicStructurePermissions::mayCreate($role, 'paralelo')
            || ! AcademicStructurePermissions::mayCreate($role, 'asignacion_docente')) {
            throw new AuthorizationException('No puede importar con el rol activo.');
        }
        $period = AcademicPeriod::query()->whereKey($periodId)->firstOrFail();
        $this->planning->assertMayPlan($period);

        $subjects = Subject::query()->where('carrera_id', $role->carrera_id)->where('activo', true)->get()
            ->keyBy(fn (Subject $subject): string => $this->key((string) $subject->codigo_asignatura));
        $teachers = RoleAssignment::query()->effective()->with('user')
            ->where('carrera_id', $role->carrera_id)->where('rol', RoleCode::Teacher->value)
            ->whereHas('user', fn ($query) => $query->where('activo', true)->whereNotNull('correo_verificado_en'))
            ->get()->keyBy(fn (RoleAssignment $assignment): string => mb_strtolower($assignment->user->correo_electronico));

        $seen = [];
        $checked = [];
        $plan = [];
        foreach ($rows as $row) {
            $values = $row['values'];
            $errors = [];
            $subject = $subjects->get($this->key($values['materia']));
            $shift = $this->key($values['jornada']);
            $code = trim($values['paralelo']);
            $email = mb_strtolower($values['docente']);
            if (! $subject instanceof Subject) {
                $errors[] = 'No existe una materia activa de su carrera con ese código.';
            }
            if (! in_array($shift, Parallel::SHIFTS, true)) {
                $errors[] = 'La jornada debe ser matutina, vespertina o nocturna.';
            }
            if ($code === '' || mb_strlen($code) > 30) {
                $errors[] = 'El paralelo es obligatorio y admite hasta 30 caracteres.';
            }
            $teacher = null;
            if ($email !== '') {
                $teacher = $teachers->get($email);
                if ($teacher === null) {
                    $errors[] = 'El docente no está en su carrera o aún no verificó su correo.';
                }
            }
            $pair = $this->key($values['materia']).'|'.$this->key($code);
            if (isset($seen[$pair])) {
                $errors[] = "La materia y el paralelo se repiten en la fila {$seen[$pair]}.";
            }
            $seen[$pair] ??= $row['line'];

            $parallel = null;
            if ($subject instanceof Subject && $code !== '') {
                $parallel = Parallel::query()->where('periodo_academico_id', $period->id)
                    ->where('asignatura_id', $subject->id)->where('codigo', $code)->first();
                if ($parallel instanceof Parallel) {
                    if ($teacher === null) {
                        $errors[] = 'El paralelo ya existe en el período; indique un docente para asignarlo.';
                    } elseif (TeacherAssignment::query()->where('paralelo_id', $parallel->id)->where('activo', true)->exists()) {
                        $errors[] = 'El paralelo ya tiene un docente asignado.';
                    }
                }
            }

            $checked[] = [...$row, 'errors' => array_values(array_unique($errors))];
            $plan[] = ['subject' => $subject, 'code' => $code, 'shift' => $shift, 'teacher' => $teacher, 'parallel' => $parallel];
        }

        $valid = collect($checked)->every(fn (array $row): bool => $row['errors'] === []);
        $created = 0;
        if ($confirm && $valid) {
            DB::transaction(function () use ($plan, $period, $actor, $request, &$created): void {
                foreach ($plan as $item) {
                    $parallelId = $item['parallel']?->id;
                    if ($parallelId === null) {
                        $this->createParallels->execute([
                            'subject_id' => $item['subject']->id,
                            'period_id' => $period->id,
                            'codes' => [$item['code']],
                            'shift' => $item['shift'],
                        ], $actor, $request);
                        $parallelId = Parallel::query()->where('periodo_academico_id', $period->id)
                            ->where('asignatura_id', $item['subject']->id)->where('codigo', $item['code'])->value('paralelo_id');
                    }
                    if ($item['teacher'] !== null) {
                        $this->createRecord->execute('asignacion_docente', [
                            'parallel_id' => $parallelId,
                            'user_id' => $item['teacher']->usuario_id,
                        ], $actor, $request);
                    }
                    $created++;
                }
            });
        }

        return ['rows' => $checked, 'valid' => $valid, 'created' => $created];
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
