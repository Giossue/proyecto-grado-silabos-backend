<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Library\Application\LibraryReviews;
use App\Modules\Operations\Application\SetupChecklist;
use App\Modules\Syllabus\Application\ConvocationSchedule;
use App\Modules\Syllabus\Application\CoordinatorConvocationReadiness;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @phpstan-import-type CoordinatorReadiness from CoordinatorConvocationReadiness
 *
 * @phpstan-type DeadlinePayload array{period: string, starts_at: string, due_at: string, state: string, days_until_start: int, days_until_due: int, convocation_id: string|null, paused_by: string|null, classes_ended: bool, career_pending: bool}
 * @phpstan-type CoordinationConvocationPayload array{process_id: string, period: string, convocation_id: string|null, can_open: bool, state: string|null, process_open: bool}
 */
class DashboardController extends Controller
{
    public function index(
        Request $request,
        ActiveRole $roles,
        SetupChecklist $setup,
        ConvocationSchedule $schedule,
        CoordinatorConvocationReadiness $readiness,
        LibraryReviews $libraryReviews,
    ): JsonResponse {
        $activeRole = $roles->resolve($request);
        $user = $request->user();
        $coordinatorReadiness = $activeRole?->role->codigo_rol === RoleCode::Coordinator->value
            && is_string($activeRole->carrera_id)
            ? $readiness->inspect($activeRole->carrera_id)
            : null;

        $metrics = match (true) {
            $activeRole === null || ! $user instanceof User => [],
            $activeRole->role->codigo_rol === RoleCode::Administrator->value => $this->administratorMetrics(),
            $activeRole->role->codigo_rol === RoleCode::Librarian->value => $this->librarianMetrics($libraryReviews),
            default => [],
        };

        $checklist = match (true) {
            $activeRole === null || ! $user instanceof User => null,
            $activeRole->role->codigo_rol === RoleCode::Administrator->value => $setup->forAdministrator(),
            $activeRole->role->codigo_rol === RoleCode::Coordinator->value => $setup->forCoordinator($activeRole->carrera_id, $coordinatorReadiness),
            default => null,
        };

        $deadline = match (true) {
            $activeRole === null || ! $user instanceof User => null,
            $activeRole->role->codigo_rol === RoleCode::Coordinator->value => $this->coordinatorDeadline(
                $coordinatorReadiness['process'] ?? null,
                ($coordinatorReadiness['convocation'] ?? null) instanceof Convocation ? $coordinatorReadiness['convocation'] : null,
                $schedule,
            ),
            $activeRole->role->codigo_rol === RoleCode::Teacher->value => $this->teacherDeadline($user, $activeRole->carrera_id, $schedule),
            $activeRole->role->codigo_rol === RoleCode::Librarian->value => $this->librarianDeadline(),
            default => null,
        };

        $payload = [
            'metrics' => $metrics,
            'setup' => $checklist,
            'deadline' => $deadline,
            'coordinationConvocation' => $activeRole?->role->codigo_rol === RoleCode::Coordinator->value
                ? $this->coordinationConvocation($activeRole->carrera_id, $coordinatorReadiness)
                : null,
        ];

        return response()->json($this->apiPayload($payload));
    }

    public function deadline(Request $request, ActiveRole $roles, ConvocationSchedule $schedule): JsonResponse
    {
        $activeRole = $roles->resolve($request);
        $user = $request->user();

        $deadline = match (true) {
            $activeRole === null || ! $user instanceof User => null,
            $activeRole->role->codigo_rol === RoleCode::Coordinator->value => $this->careerDeadline($activeRole->carrera_id, $schedule),
            $activeRole->role->codigo_rol === RoleCode::Teacher->value => $this->teacherDeadline($user, $activeRole->carrera_id, $schedule),
            $activeRole->role->codigo_rol === RoleCode::Librarian->value => $this->librarianDeadline(),
            $activeRole->role->codigo_rol === RoleCode::Administrator->value => $this->administratorPause(),
            default => null,
        };

        return response()->json(['deadline' => $deadline]);
    }

    /**
     * @param  array{metrics: array<int, array<string, mixed>>, setup: array<string, mixed>|null, deadline: array<string, mixed>|null, coordinationConvocation: array<string, mixed>|null}  $payload
     * @return array{metrics: array<int, array<string, mixed>>, setup: array<string, mixed>|null, deadline: array<string, mixed>|null, coordination_convocation: array<string, mixed>|null}
     */
    private function apiPayload(array $payload): array
    {
        $setup = $payload['setup'];

        if (is_array($setup) && is_array($setup['steps'] ?? null)) {
            $setup['steps'] = array_map(
                fn (array $step): array => [
                    'key' => $step['key'],
                    'label' => $step['label'],
                    'hint' => $step['hint'],
                    'done' => $step['done'],
                    'action' => $step['action'],
                ],
                $setup['steps'],
            );
        }

        return [
            'metrics' => $payload['metrics'],
            'setup' => $setup,
            'deadline' => $payload['deadline'],
            'coordination_convocation' => $payload['coordinationConvocation'],
        ];
    }

    /** @return list<array{key: string, label: string, value: int, suffix: string|null, hint: string}> */
    private function administratorMetrics(): array
    {
        $process = SyllabusProcess::query()->inProgress()->first();
        $syllabi = Syllabus::query()->when(
            $process !== null,
            fn (Builder $query) => $query->whereHas('convocation', fn (Builder $convocation) => $convocation->where('proceso_id', $process->id)),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
        $total = (clone $syllabi)->count();
        $approved = (clone $syllabi)->where('estado', 'aprobado')->count();
        $careersWithoutConvocation = $process === null
            ? 0
            : Career::query()->where('activo', true)
                ->whereNotIn('id', Convocation::query()->where('proceso_id', $process->id)->select('carrera_id'))
                ->count();

        return [
            $this->progressMetric($approved, $total, $process === null ? 'Sin proceso en curso' : null),
            $this->daysLeftMetric($process?->entrega_en, $process === null ? 'Sin proceso en curso' : null),
            [
                'key' => 'careers_without_convocation',
                'label' => 'Carreras sin convocar',
                'value' => $careersWithoutConvocation,
                'suffix' => null,
                'hint' => $process === null ? 'Sin proceso en curso' : 'Coordinaciones que aún no abren su convocatoria',
            ],
            [
                'key' => 'not_started',
                'label' => 'Sílabos sin iniciar',
                'value' => (clone $syllabi)->where('estado', 'sin_iniciar')->count(),
                'suffix' => null,
                'hint' => 'Docentes que todavía no empiezan',
            ],
        ];
    }

    /** @return list<array{key: string, label: string, value: int, suffix: string|null, hint: string}> */
    private function librarianMetrics(LibraryReviews $reviews): array
    {
        $counts = $reviews->counts();

        return [
            [
                'key' => 'library_pending',
                'label' => 'En revisión',
                'value' => $counts[LibraryReviews::STATE_PENDING],
                'suffix' => null,
                'hint' => $counts[LibraryReviews::STATE_PENDING] === 0 ? 'Nada pendiente por ahora' : 'Aprobados por coordinación; falta la bibliografía',
            ],
            [
                'key' => 'library_signed',
                'label' => 'Aprobados',
                'value' => $counts[LibraryReviews::OUTCOME_SIGNED],
                'suffix' => null,
                'hint' => 'Bibliografía aprobada y firmada',
            ],
            [
                'key' => 'library_commented',
                'label' => 'Corrección solicitada',
                'value' => $counts[LibraryReviews::OUTCOME_RETURNED],
                'suffix' => null,
                'hint' => 'Con observaciones para el docente',
            ],
        ];
    }

    /** @return DeadlinePayload|null */
    private function careerDeadline(?string $careerId, ConvocationSchedule $schedule): ?array
    {
        $process = $this->latestProcess();
        if ($process === null || $careerId === null) {
            return null;
        }

        return $this->coordinatorDeadline(
            $process,
            Convocation::query()->where('carrera_id', $careerId)->where('proceso_id', $process->id)->first(),
            $schedule,
        );
    }

    /** @return DeadlinePayload|null */
    private function coordinatorDeadline(mixed $process, ?Convocation $convocation, ConvocationSchedule $schedule): ?array
    {
        if (! $process instanceof SyllabusProcess) {
            return null;
        }

        if ($convocation !== null) {
            $convocation->loadMissing('process.academicPeriod');
            $startsAt = $schedule->startsAt($convocation);
            $dueAt = $schedule->draftDeadline($convocation);
        } else {
            $process->loadMissing('academicPeriod');
            $startsAt = $process->inicia_en;
            $dueAt = $process->entrega_en;
        }

        return [
            ...$this->deadlinePayload($process, $convocation, $startsAt, $dueAt),
            'career_pending' => $convocation === null || $convocation->estado === Convocation::STATE_PREPARATION,
        ];
    }

    /** @return DeadlinePayload|null */
    private function teacherDeadline(User $user, ?string $careerId, ConvocationSchedule $schedule): ?array
    {
        $syllabus = Syllabus::query()
            ->where('estado', '!=', 'aprobado')
            ->whereCurrentTeacher($user)
            ->when($careerId !== null, fn (Builder $query) => $query->whereHas('convocation', fn (Builder $convocation) => $convocation->where('carrera_id', $careerId)))
            ->whereHas('convocation.process', fn (Builder $process) => $process->whereIn('estado', [SyllabusProcess::STATE_OPEN, SyllabusProcess::STATE_PAUSED]))
            ->with('convocation.process.academicPeriod')
            ->get()
            ->sortBy(fn (Syllabus $item): int => $schedule->draftDeadline($item->convocation)->getTimestamp())
            ->first();

        if (! $syllabus instanceof Syllabus) {
            return $this->finalizedTeacherDeadline($user, $careerId);
        }

        $convocation = $syllabus->convocation;
        $process = $convocation->process;

        return $this->deadlinePayload(
            $process,
            $convocation,
            $schedule->startsAt($convocation),
            $schedule->draftDeadline($convocation),
        );
    }

    /**
     * El docente sigue viendo la franja de la convocatoria finalizada en la que tuvo sílabos, hasta que haya otra.
     *
     * @return DeadlinePayload|null
     */
    private function finalizedTeacherDeadline(User $user, ?string $careerId): ?array
    {
        $process = $this->latestProcess();
        if ($process === null || $process->estado !== SyllabusProcess::STATE_CLOSED) {
            return null;
        }
        $syllabus = Syllabus::query()
            ->whereCurrentTeacher($user)
            ->whereHas('convocation', fn (Builder $convocation) => $convocation
                ->where('proceso_id', $process->id)
                ->when($careerId !== null, fn (Builder $query) => $query->where('carrera_id', $careerId)))
            ->with('convocation')
            ->first();

        return $syllabus instanceof Syllabus
            ? $this->deadlinePayload($process, $syllabus->convocation, $process->inicia_en, $process->entrega_en)
            : null;
    }

    /**
     * La convocatoria que manda la franja: la que está en curso o, si no hay, la última finalizada,
     * que sigue a la vista hasta que Administración abra otra.
     */
    private function latestProcess(): ?SyllabusProcess
    {
        return SyllabusProcess::query()->inProgress()->with('academicPeriod')->first()
            ?? SyllabusProcess::query()->with('academicPeriod')->get()
                ->sortByDesc(fn (SyllabusProcess $process): string => $process->academicPeriod->fecha_inicio->toDateString())
                ->first();
    }

    /**
     * Administración no tiene plazo propio: solo ve el aviso cuando la convocatoria está en pausa o finalizada.
     *
     * @return DeadlinePayload|null
     */
    private function administratorPause(): ?array
    {
        $process = $this->latestProcess();

        return $process === null || $process->estado === SyllabusProcess::STATE_OPEN
            ? null
            : $this->deadlinePayload($process, null, $process->inicia_en, $process->entrega_en);
    }

    /** @return DeadlinePayload|null */
    private function librarianDeadline(): ?array
    {
        $process = $this->latestProcess();

        return $process === null ? null : $this->deadlinePayload($process, null, $process->inicia_en, $process->entrega_en);
    }

    /** @return DeadlinePayload */
    private function deadlinePayload(
        SyllabusProcess $process,
        ?Convocation $convocation,
        CarbonInterface $startsAt,
        CarbonInterface $dueAt,
    ): array {
        $today = InstitutionDate::todayOn();
        $start = InstitutionDate::dateOf($startsAt);
        $due = InstitutionDate::dateOf($dueAt);
        $state = match (true) {
            $process->estado === SyllabusProcess::STATE_CLOSED || $convocation?->estado === Convocation::STATE_CLOSED => 'closed',
            $process->estado === SyllabusProcess::STATE_PAUSED || $convocation?->estado === Convocation::STATE_PAUSED => 'paused',
            $today < $start => 'upcoming',
            $today > $due => 'overdue',
            default => 'active',
        };

        return [
            'period' => $process->academicPeriod->codigo,
            'starts_at' => $start,
            'due_at' => $due,
            'state' => $state,
            'days_until_start' => max(0, InstitutionDate::daysBetween($today, $start)),
            'days_until_due' => max(0, InstitutionDate::daysBetween($today, $due)),
            'convocation_id' => $convocation?->id,
            // Quién pausó: Administración (convocatoria institucional) o Coordinación (la de la carrera).
            'paused_by' => $state !== 'paused' ? null : ($process->estado === SyllabusProcess::STATE_PAUSED ? 'administracion' : 'coordinacion'),
            'classes_ended' => $today > $process->academicPeriod->fecha_fin->toDateString(),
            'career_pending' => false,
        ];
    }

    /**
     * @param  CoordinatorReadiness|null  $readiness
     * @return CoordinationConvocationPayload|null
     */
    private function coordinationConvocation(?string $careerId, ?array $readiness): ?array
    {
        $process = $readiness['process'] ?? null;
        if (! is_string($careerId) || ! $process instanceof SyllabusProcess) {
            return null;
        }
        $convocation = $readiness['convocation'] ?? null;
        $convocation = $convocation instanceof Convocation ? $convocation : null;

        return [
            'process_id' => $process->id,
            'period' => $process->academicPeriod->codigo,
            'convocation_id' => $convocation?->id,
            'can_open' => $readiness['can_open'],
            'state' => $convocation?->estado,
            'process_open' => $process->estado === SyllabusProcess::STATE_OPEN,
        ];
    }

    /** @return array{key: string, label: string, value: int, suffix: string|null, hint: string} */
    private function progressMetric(int $approved, int $total, ?string $none): array
    {
        return [
            'key' => 'progress',
            'label' => 'Avance del proceso',
            'value' => $total === 0 ? 0 : (int) round($approved * 100 / $total),
            'suffix' => '%',
            'hint' => $none ?? "{$approved} de {$total} sílabos aprobados",
        ];
    }

    /** @return array{key: string, label: string, value: int, suffix: string|null, hint: string} */
    private function daysLeftMetric(mixed $deadline, ?string $none): array
    {
        $today = InstitutionDate::todayOn();
        $dueOn = InstitutionDate::dateOfValue($deadline);
        $days = $dueOn === null ? 0 : max(0, InstitutionDate::daysBetween($today, $dueOn));
        $hint = match (true) {
            $none !== null => $none,
            $dueOn === null => 'Sin fecha de entrega',
            $dueOn < $today => 'El plazo venció el '.CarbonImmutable::parse($dueOn, 'UTC')->translatedFormat('j \d\e F'),
            default => 'Entrega hasta el '.CarbonImmutable::parse($dueOn, 'UTC')->translatedFormat('j \d\e F'),
        };

        return ['key' => 'days_left', 'label' => 'Días para la entrega', 'value' => $days, 'suffix' => 'días', 'hint' => $hint];
    }
}
