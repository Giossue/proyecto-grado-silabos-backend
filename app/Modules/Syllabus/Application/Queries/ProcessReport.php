<?php

namespace App\Modules\Syllabus\Application\Queries;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;

/**
 * Reporte de una convocatoria institucional: avance por carrera, envíos por día, devoluciones,
 * tiempos de revisión y sílabos pendientes. Solo lee; lo usan la pantalla y el Excel.
 */
final class ProcessReport
{
    public const STATES = ['sin_iniciar', 'borrador', 'en_revision', 'correccion_solicitada', 'aprobado'];

    /** @return list<array{value: string, label: string}> */
    public function options(): array
    {
        return array_values(SyllabusProcess::query()->with('academicPeriod')->orderByDesc('inicia_en')->get()
            ->map(fn (SyllabusProcess $process): array => ['value' => $process->id, 'label' => (string) $process->academicPeriod?->codigo])
            ->all());
    }

    /** @return array<string, mixed> */
    public function build(SyllabusProcess $process): array
    {
        $process->loadMissing('academicPeriod');
        $syllabi = Syllabus::query()
            ->whereHas('convocation', fn ($query) => $query->where('proceso_id', $process->id))
            ->with(['convocation.career', 'teachers:id,nombre', 'revisions' => fn ($query) => $query->orderBy('numero_revision')->with('approval')])
            ->get();
        $timezone = InstitutionDate::timezone();
        $dueAt = CarbonImmutable::parse(InstitutionDate::dateOf($process->entrega_en), $timezone)->endOfDay();
        $now = CarbonImmutable::now($timezone);

        $careers = [];
        $daily = [];
        $pending = [];
        $submitted = 0;
        $onTime = 0;
        $reviewDays = [];
        foreach ($syllabi as $syllabus) {
            $career = $syllabus->convocation->career->nombre;
            $careers[$career] ??= ['career' => $career, 'total' => 0, 'states' => array_fill_keys(self::STATES, 0), 'returns' => ['coordinacion' => 0, 'biblioteca' => 0], 'late' => 0];
            $row = &$careers[$career];
            $row['total']++;
            $state = in_array($syllabus->estado, self::STATES, true) ? $syllabus->estado : 'borrador';
            $row['states'][$state]++;

            /** @var SyllabusRevision|null $first */
            $first = $syllabus->revisions->first();
            foreach ($syllabus->revisions as $revision) {
                $day = $revision->enviado_en->setTimezone($timezone)->toDateString();
                $kind = $revision->numero_revision === 1 ? 'first' : 'resubmitted';
                $daily[$day][$kind] = ($daily[$day][$kind] ?? 0) + 1;
                if ($revision->correccion_etapa === 'coordinacion' || $revision->correccion_etapa === 'biblioteca') {
                    $row['returns'][$revision->correccion_etapa]++;
                }
            }
            if ($first instanceof SyllabusRevision) {
                $submitted++;
                if ($first->enviado_en->lessThanOrEqualTo($dueAt)) {
                    $onTime++;
                }
                $approval = $syllabus->revisions->map(fn (SyllabusRevision $revision): ?Approval => $revision->approval)->filter()->last();
                if ($state === 'aprobado' && $approval instanceof Approval) {
                    $reviewDays[] = $first->enviado_en->diffInSeconds($approval->aprobado_en) / 86400;
                }
            }
            $late = $state !== 'aprobado' && $state !== 'en_revision' && $now->greaterThan($dueAt);
            if ($late) {
                $row['late']++;
            }
            if ($state !== 'aprobado') {
                $pending[] = [
                    'teachers' => $syllabus->teachers->pluck('nombre')->unique()->implode(', '),
                    'career' => $career,
                    'subject' => $syllabus->academicSubjectName(),
                    'parallel' => $syllabus->academicParallelCode(),
                    'state' => $state,
                    'late' => $late,
                ];
            }
            unset($row);
        }
        ksort($careers);
        ksort($daily);
        usort($pending, fn (array $a, array $b): int => [$a['career'], $a['teachers'], $a['subject']] <=> [$b['career'], $b['teachers'], $b['subject']]);
        $total = $syllabi->count();
        $approved = array_sum(array_map(fn (array $row): int => $row['states']['aprobado'], $careers));

        return [
            'process' => [
                'id' => $process->id,
                'period' => $process->academicPeriod?->codigo,
                'starts_at' => InstitutionDate::dateOf($process->inicia_en),
                'due_at' => InstitutionDate::dateOf($process->entrega_en),
                'state' => $process->estado,
            ],
            'kpis' => [
                'total' => $total,
                'approved_percent' => $total === 0 ? 0 : round($approved * 100 / $total),
                'on_time_percent' => $submitted === 0 ? null : round($onTime * 100 / $submitted),
                'review_days' => $reviewDays === [] ? null : round(array_sum($reviewDays) / count($reviewDays), 1),
            ],
            'careers' => array_values(array_map(fn (array $row): array => [
                ...$row,
                'approved' => $row['states']['aprobado'],
                'pending' => $row['total'] - $row['states']['aprobado'],
                'returned' => $row['states']['correccion_solicitada'],
                // Cada carrera del reporte tiene al menos un sílabo.
                'progress' => round($row['states']['aprobado'] * 100 / max(1, $row['total'])),
            ], $careers)),
            'daily' => array_map(fn (string $day, array $counts): array => ['day' => $day, 'first' => $counts['first'] ?? 0, 'resubmitted' => $counts['resubmitted'] ?? 0], array_keys($daily), $daily),
            'pending' => $pending,
        ];
    }
}
