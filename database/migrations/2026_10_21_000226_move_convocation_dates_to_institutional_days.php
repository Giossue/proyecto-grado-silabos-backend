<?php

use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('convocatorias_universidad')
            ->select('convocatoria_universidad_id', 'fecha_inicio', 'fecha_entrega')
            ->get();

        foreach ($rows as $row) {
            $inicio = CarbonImmutable::parse($row->fecha_inicio)->setTimezone('UTC');
            $entrega = CarbonImmutable::parse($row->fecha_entrega)->setTimezone('UTC');

            $nuevoInicio = $inicio->format('H:i:s') === '00:00:00'
                ? InstitutionDate::startOfDay($inicio->toDateString())
                : null;
            $nuevaEntrega = $entrega->format('H:i:s') === '23:59:59'
                ? InstitutionDate::endOfDay($entrega->toDateString())
                : null;

            if ($nuevoInicio === null && $nuevaEntrega === null) {
                continue;
            }

            DB::table('convocatorias_universidad')
                ->where('convocatoria_universidad_id', $row->convocatoria_universidad_id)
                ->update(array_filter([
                    'fecha_inicio' => $nuevoInicio?->toIso8601String(),
                    'fecha_entrega' => $nuevaEntrega?->toIso8601String(),
                ], fn (mixed $value): bool => $value !== null));
        }
    }

    public function down(): void
    {
        $rows = DB::table('convocatorias_universidad')
            ->select('convocatoria_universidad_id', 'fecha_inicio', 'fecha_entrega')
            ->get();

        foreach ($rows as $row) {
            $inicio = CarbonImmutable::parse($row->fecha_inicio)->setTimezone(InstitutionDate::timezone());
            $entrega = CarbonImmutable::parse($row->fecha_entrega)->setTimezone(InstitutionDate::timezone());

            $anteriorInicio = $inicio->format('H:i:s') === '00:00:00'
                ? CarbonImmutable::parse($inicio->toDateString(), 'UTC')->startOfDay()
                : null;
            $anteriorEntrega = $entrega->format('H:i:s') === '23:59:59'
                ? CarbonImmutable::parse($entrega->toDateString(), 'UTC')->endOfDay()
                : null;

            if ($anteriorInicio === null && $anteriorEntrega === null) {
                continue;
            }

            DB::table('convocatorias_universidad')
                ->where('convocatoria_universidad_id', $row->convocatoria_universidad_id)
                ->update(array_filter([
                    'fecha_inicio' => $anteriorInicio?->toIso8601String(),
                    'fecha_entrega' => $anteriorEntrega?->toIso8601String(),
                ], fn (mixed $value): bool => $value !== null));
        }
    }
};
