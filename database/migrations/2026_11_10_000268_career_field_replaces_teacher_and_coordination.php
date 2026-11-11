<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une los responsables «docente» y «coordinacion» en «carrera». Los sílabos y las revisiones
 * enviadas no se tocan. No cuenta como edición de la plantilla: los triggers ponen
 * plantilla_modificada_en y aquí se deja la fecha que tenía, porque al reanudar una
 * convocatoria esa fecha rehace los sílabos (como en 000264).
 */
return new class extends Migration
{
    private const LEGACY = ['docente', 'coordinacion'];

    public function up(): void
    {
        $this->keepingTemplateDate(fn () => $this->toCareer());
    }

    public function down(): void
    {
        $this->keepingTemplateDate(fn () => $this->toLegacy());
    }

    private function keepingTemplateDate(callable $change): void
    {
        $dates = DB::table('plantilla_silabo')->pluck('plantilla_modificada_en', 'plantilla_id');
        $change();
        foreach ($dates as $id => $date) {
            DB::table('plantilla_silabo')->where('plantilla_id', $id)->update(['plantilla_modificada_en' => $date]);
        }
    }

    private function toCareer(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_responsable_check;
            UPDATE definiciones_campo SET quien_llena_definicion_campo = 'carrera'
                WHERE quien_llena_definicion_campo IN ('docente', 'coordinacion');
            ALTER TABLE definiciones_campo ALTER COLUMN quien_llena_definicion_campo SET DEFAULT 'carrera';
            ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_responsable_check
                CHECK (quien_llena_definicion_campo IN ('carrera', 'institucional'));
            SQL);
        $this->rewritePresets(fn (string $responsible): string => in_array($responsible, self::LEGACY, true) ? 'carrera' : $responsible);
    }

    private function toLegacy(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_responsable_check;
            UPDATE definiciones_campo SET quien_llena_definicion_campo = 'docente'
                WHERE quien_llena_definicion_campo = 'carrera';
            ALTER TABLE definiciones_campo ALTER COLUMN quien_llena_definicion_campo SET DEFAULT 'docente';
            ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_responsable_check
                CHECK (quien_llena_definicion_campo IN ('docente', 'coordinacion', 'institucional'));
            SQL);
        $this->rewritePresets(fn (string $responsible): string => $responsible === 'carrera' ? 'docente' : $responsible);
    }

    /** @param  callable(string): string  $map */
    private function rewritePresets(callable $map): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            if (! is_array($definition) || ! is_array($definition['presets'] ?? null)) {
                continue;
            }
            $changed = false;
            foreach ($definition['presets'] as $index => $preset) {
                if (is_array($preset) && is_string($preset['quien_llena'] ?? null) && $map($preset['quien_llena']) !== $preset['quien_llena']) {
                    $definition['presets'][$index]['quien_llena'] = $map($preset['quien_llena']);
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                    ->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            }
        }
    }
};
