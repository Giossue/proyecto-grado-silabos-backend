<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\SetupChecklist;
use App\Modules\Syllabus\Application\ConvocationSchedule;
use App\Modules\Syllabus\Application\CoordinatorConvocationReadiness;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * El panel resume el trabajo con el alcance del rol activo. Ninguna métrica cruza ese
 * alcance: Coordinación cuenta dentro de su carrera y Docencia solo lo propio.
 *
 * @phpstan-import-type CoordinatorReadiness from CoordinatorConvocationReadiness
 *
 * @phpstan-type DeadlinePayload array{period: string, starts_at: string, due_at: string, state: string, days_until_start: int, days_until_due: int, convocation_id: string|null}
 * @phpstan-type CoordinationConvocationPayload array{process_id: string, period: string, convocation_id: string|null, can_open: bool}
 */
class DashboardController extends Controller
{
    public function index(
        Request $request,
        ActiveRole $roles,
        SetupChecklist $setup,
        ConvocationSchedule $schedule,
        CoordinatorConvocationReadiness $readiness,
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
            $activeRole->role->codigo_rol === RoleCode::Coordinator->value => $this->coordinatorMetrics($coordinatorReadiness),
            $activeRole->role->codigo_rol === RoleCode::Teacher->value => $this->teacherMetrics($user, $activeRole->carrera_id),
            default => [],
        };

        $checklist = match (true) {
            $activeRole === null || ! $user instanceof User => null,
            $activeRole->role->codigo_rol === RoleCode::Administrator->value => $setup->forAdministrator(),
            $activeRole->role->codigo_rol === RoleCode::Coordinator->value => $setup->forCoordinator($activeRole->carrera_id, $coordinatorReadiness),
            $activeRole->role->codigo_rol === RoleCode::Teacher->value => $setup->forTeacher($user, $activeRole->carrera_id),
            default => null,
        };

        $deadline = match (true) {
            $activeRole === null || ! $user instanceof User => null,
            $activeRole->role->codigo_rol === RoleCode::Coordinator->value => $this->coordinatorDeadline($coordinatorReadiness, $schedule),
            $activeRole->role->codigo_rol === RoleCode::Teacher->value => $this->teacherDeadline($user, $activeRole->carrera_id, $schedule),
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
            ? Career::query()->where('activo', true)->count()
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
                'hint' => 'Coordinaciones que aún no abren su convocatoria',
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

    /**
     * @param  CoordinatorReadiness|null  $readiness
     * @return list<array{key: string, label: string, value: int, suffix: string|null, hint: string}>
     */
    private function coordinatorMetrics(?array $readiness): array
    {
        $convocation = $readiness['convocation'] ?? null;
        $convocation = $convocation instanceof Convocation ? $convocation : null;
        $syllabi = Syllabus::query()->when(
            $convocation !== null,
            fn (Builder $query) => $query->where('convocatoria_id', $convocation->id),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
        $total = (clone $syllabi)->count();
        $approved = (clone $syllabi)->where('estado', 'aprobado')->count();
        $none = $convocation === null ? 'Sin convocatoria en curso' : null;

        return [
            $this->progressMetric($approved, $total, $none),
            [
                'key' => 'in_review',
                'label' => 'Por revisar',
                'value' => (clone $syllabi)->where('estado', 'en_revision')->count(),
                'suffix' => null,
                'hint' => 'Enviados por los docentes y a la espera de su revisión',
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
    private function teacherMetrics(User $user, ?string $careerId): array
    {
        $own = fn (): Builder => Syllabus::query()
            ->whereHas('convocation', fn (Builder $query) => $query->where('carrera_id', $careerId))
            ->whereCurrentTeacher($user);
        $pending = $own()->whereNotIn('estado', ['en_revision', 'aprobado'])->count();

        return [
            [
                'key' => 'pending',
                'label' => 'Sílabos por entregar',
                'value' => $pending,
                'suffix' => null,
                'hint' => $pending === 0 ? 'Nada pendiente por ahora' : 'Aún no enviados a revisión',
            ],
            [
                'key' => 'correction_requested',
                'label' => 'Por corregir',
                'value' => $own()->where('estado', 'correccion_solicitada')->count(),
                'suffix' => null,
                'hint' => 'Devueltos por Coordinación con observaciones',
            ],
        ];
    }

    /**
     * @param  CoordinatorReadiness|null  $readiness
     * @return DeadlinePayload|null
     */
    private function coordinatorDeadline(?array $readiness, ConvocationSchedule $schedule): ?array
    {
        $process = $readiness['process'] ?? null;
        if (! $process instanceof SyllabusProcess) {
            return null;
        }

        $convocation = $readiness['convocation'] ?? null;
        $convocation = $convocation instanceof Convocation ? $convocation : null;
        if ($convocation !== null) {
            $convocation->loadMissing('process.academicPeriod');
            $startsAt = $schedule->startsAt($convocation);
            $dueAt = $schedule->draftDeadline($convocation);
        } else {
            $process->loadMissing('academicPeriod');
            $startsAt = $process->inicia_en;
            $dueAt = $process->entrega_en;
        }

        return $this->deadlinePayload($process, $convocation, $startsAt, $dueAt);
    }

    /** @return DeadlinePayload|null */
    private function teacherDeadline(User $user, ?string $careerId, ConvocationSchedule $schedule): ?array
    {
        $syllabus = Syllabus::query()
            ->whereNotIn('estado', ['aprobado'])
            ->whereCurrentTeacher($user)
            ->when($careerId !== null, fn (Builder $query) => $query->whereHas('convocation', fn (Builder $convocation) => $convocation->where('carrera_id', $careerId)))
            ->whereHas('convocation.process', fn (Builder $process) => $process->whereIn('estado', [SyllabusProcess::STATE_OPEN, SyllabusProcess::STATE_PAUSED]))
            ->with(['convocation.process.academicPeriod'])
            ->get()
            ->sortBy(fn (Syllabus $item): int => $schedule->draftDeadline($item->convocation)->getTimestamp())
            ->first();

        if (! $syllabus instanceof Syllabus) {
            return null;
        }

        $convocation = $syllabus->convocation;
        $process = $convocation->process;
        $startsAt = $schedule->startsAt($convocation);
        $dueAt = $schedule->draftDeadline($convocation);

        return $this->deadlinePayload($process, $convocation, $startsAt, $dueAt);
    }

    /** @return DeadlinePayload */
    private function deadlinePayload(
        SyllabusProcess $process,
        ?Convocation $convocation,
        CarbonInterface $startsAt,
        CarbonInterface $dueAt,
    ): array {
        $today = now()->startOfDay();
        $start = $startsAt->copy()->startOfDay();
        $due = $dueAt->copy()->startOfDay();
        $state = match (true) {
            $process->estado === SyllabusProcess::STATE_CLOSED || $convocation?->estado === Convocation::STATE_CLOSED => 'closed',
            $process->estado === SyllabusProcess::STATE_PAUSED || $convocation?->estado === Convocation::STATE_PAUSED => 'paused',
            $today->lessThan($start) => 'upcoming',
            $today->greaterThan($due) => 'overdue',
            default => 'active',
        };

        return [
            'period' => $process->academicPeriod->codigo,
            'starts_at' => $startsAt->toIso8601String(),
            'due_at' => $dueAt->toIso8601String(),
            'state' => $state,
            'days_until_start' => max(0, (int) $today->diffInDays($start, false)),
            'days_until_due' => max(0, (int) $today->diffInDays($due, false)),
            'convocation_id' => $convocation?->id,
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
        $due = $deadline === null ? null : ($deadline instanceof CarbonInterface ? $deadline : Carbon::parse((string) $deadline));
        $days = $due === null ? 0 : max(0, (int) now()->startOfDay()->diffInDays($due->copy()->startOfDay(), false));
        $hint = match (true) {
            $none !== null => $none,
            $due === null => 'Sin fecha de entrega',
            $due->isPast() => 'El plazo venció el '.$due->translatedFormat('j \d\e F'),
            default => 'Entrega hasta el '.$due->translatedFormat('j \d\e F'),
        };

        return ['key' => 'days_left', 'label' => 'Días para la entrega', 'value' => $days, 'suffix' => 'días', 'hint' => $hint];
    }
}
