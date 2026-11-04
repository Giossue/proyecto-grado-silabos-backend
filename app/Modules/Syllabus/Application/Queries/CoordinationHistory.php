<?php

namespace App\Modules\Syllabus\Application\Queries;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\Search\AccentInsensitive;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CoordinationHistory
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Syllabus> */
    public function page(string $careerId, ?string $search, ?string $periodId): LengthAwarePaginator
    {
        $query = $this->approvedInPastPeriods($careerId)
            ->with([
                'convocation.process.academicPeriod:id,codigo',
                'parallel.subject',
                'revisions' => fn ($revision) => $revision
                    ->whereHas('approval')
                    ->with(['approval', 'submitter:id,nombre'])
                    ->orderByDesc('numero_revision'),
            ]);
        if ($periodId !== null) {
            $query->whereHas('convocation.process', fn (Builder $process): Builder => $process->where('periodo_academico_id', $periodId));
        }
        if ($search !== null && $search !== '') {
            $query->where(fn (Builder $outer): Builder => $outer
                ->whereRaw(AccentInsensitive::matches("datos_academicos_silabo->'subject'->>'name'"), [AccentInsensitive::pattern($search)])
                ->orWhereRaw(AccentInsensitive::matches("datos_academicos_silabo->'subject'->>'code'"), [AccentInsensitive::pattern($search)]));
        }

        return $query
            ->orderByDesc(DB::table('convocatorias_carreras as cc')
                ->join('convocatorias_universidad as cu', 'cu.convocatoria_universidad_id', '=', 'cc.convocatoria_universidad_id')
                ->join('periodos_academicos as pa', 'pa.periodo_academico_id', '=', 'cu.periodo_academico_id')
                ->whereColumn('cc.convocatoria_carrera_id', 'silabos.convocatoria_carrera_id')
                ->select('pa.fecha_fin_periodo'))
            ->orderByRaw("datos_academicos_silabo->'subject'->>'name'")
            ->orderByRaw("datos_academicos_silabo->'parallel'->>'code'")
            ->orderBy('silabos.silabo_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /** @return Collection<int, AcademicPeriod> */
    public function periods(string $careerId): Collection
    {
        return AcademicPeriod::query()
            ->whereIn('periodo_academico_id', DB::table('convocatorias_universidad as cu')
                ->join('convocatorias_carreras as cc', 'cc.convocatoria_universidad_id', '=', 'cu.convocatoria_universidad_id')
                ->whereIn('cc.convocatoria_carrera_id', $this->approvedInPastPeriods($careerId)->select('silabos.convocatoria_carrera_id'))
                ->select('cu.periodo_academico_id'))
            ->orderByDesc('fecha_fin_periodo')
            ->get(['periodo_academico_id', 'codigo_periodo_academico']);
    }

    /** @return Builder<Syllabus> */
    private function approvedInPastPeriods(string $careerId): Builder
    {
        return Syllabus::query()
            ->where('estado', 'aprobado')
            ->inPastPeriod()
            ->whereHas('convocation', fn (Builder $convocation): Builder => $convocation->where('carrera_id', $careerId))
            ->whereHas('approvals');
    }
}
