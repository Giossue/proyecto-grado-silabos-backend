<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ProcessClosing
{
    public const CLOSED_DOCUMENT = 'closed_document';

    public const SNAPSHOT_STATES = ['en_revision', 'aprobado'];

    public function __construct(private readonly SyllabusSnapshot $snapshots) {}

    public function close(SyllabusProcess $locked): void
    {
        SyllabusProcess::query()->whereKey($locked->id)->update([
            'estado' => SyllabusProcess::STATE_CLOSED,
            'finalizada_en' => DB::raw('clock_timestamp()'),
            ...($locked->estado === SyllabusProcess::STATE_PAUSED ? [] : ['pausado_en' => null]),
        ]);
        $locked->refresh();
        $syllabi = Syllabus::query()->whereHas('convocation', fn ($query) => $query->where('proceso_id', $locked->id))
            ->whereNotIn('estado', self::SNAPSHOT_STATES)->lockForUpdate()->get();
        foreach ($syllabi as $syllabus) {
            $snapshot = $this->snapshots->build($syllabus);
            $syllabus->update(['contexto_academico' => [...$syllabus->contexto_academico, self::CLOSED_DOCUMENT => $snapshot]]);
        }
        Convocation::query()
            ->where('proceso_id', $locked->id)
            ->whereIn('estado', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED])
            ->update([
                'estado_previo' => DB::raw('estado_convocatoria_carrera'),
                'estado' => Convocation::STATE_CLOSED,
            ]);
    }

    public function refreshSnapshot(Syllabus $syllabus): void
    {
        $syllabus->loadMissing('convocation');
        if ($syllabus->convocation->estado !== Convocation::STATE_CLOSED || in_array($syllabus->estado, self::SNAPSHOT_STATES, true)) {
            return;
        }
        $previous = $syllabus->contexto_academico[self::CLOSED_DOCUMENT] ?? null;
        $context = Arr::except($syllabus->contexto_academico, [self::CLOSED_DOCUMENT]);
        $syllabus->contexto_academico = $context;
        $snapshot = $this->snapshots->rebuild($syllabus, is_array($previous) ? $previous : null);
        $syllabus->contexto_academico = [...$context, self::CLOSED_DOCUMENT => $snapshot];
        $syllabus->save();
    }

    /** @return list<string> Convocatorias de carrera que vuelven a quedar abiertas. */
    public function reopen(SyllabusProcess $locked): array
    {
        $convocations = Convocation::query()
            ->where('proceso_id', $locked->id)
            ->where('estado', Convocation::STATE_CLOSED)
            ->whereNotNull('estado_previo_cierre_convocatoria_carrera')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $syllabi = Syllabus::query()
            ->whereIn('convocatoria_id', $convocations->modelKeys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($syllabi as $syllabus) {
            if (array_key_exists(self::CLOSED_DOCUMENT, $syllabus->contexto_academico)) {
                $syllabus->update(['contexto_academico' => Arr::except($syllabus->contexto_academico, [self::CLOSED_DOCUMENT])]);
            }
        }
        $reopened = array_values(array_map('strval', $convocations
            ->filter(fn (Convocation $convocation): bool => $convocation->estado_previo === Convocation::STATE_OPEN)
            ->modelKeys()));
        Convocation::query()->whereKey($convocations->modelKeys())->update([
            'estado' => DB::raw('estado_previo_cierre_convocatoria_carrera'),
            'estado_previo' => null,
        ]);
        SyllabusProcess::query()->whereKey($locked->id)->update([
            'estado' => SyllabusProcess::STATE_OPEN,
            'finalizada_en' => null,
            'pausado_en' => null,
        ]);
        $locked->refresh();

        return $reopened;
    }

    public static function templateReference(SyllabusProcess $closed): ?CarbonImmutable
    {
        return $closed->pausado_en ?? $closed->finalizada_en;
    }
}
