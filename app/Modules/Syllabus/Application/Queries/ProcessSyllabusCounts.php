<?php

namespace App\Modules\Syllabus\Application\Queries;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Support\Facades\DB;

final class ProcessSyllabusCounts
{
    /** @var array<string, int> */
    public const EMPTY = [
        'sin_iniciar' => 0,
        'borrador' => 0,
        'en_revision' => 0,
        'correccion_solicitada' => 0,
        'aprobado' => 0,
    ];

    /** @return array<string, int> */
    public function byState(SyllabusProcess $process): array
    {
        $counts = self::EMPTY;
        $rows = DB::table('silabos')
            ->join('convocatorias_carreras', 'convocatorias_carreras.convocatoria_carrera_id', '=', 'silabos.convocatoria_carrera_id')
            ->where('convocatorias_carreras.convocatoria_universidad_id', $process->id)
            ->select('silabos.estado_silabo', DB::raw('count(*) as total'))
            ->groupBy('silabos.estado_silabo')
            ->get();
        foreach ($rows as $row) {
            $counts[(string) $row->estado_silabo] = (int) $row->total;
        }

        return $counts;
    }
}
