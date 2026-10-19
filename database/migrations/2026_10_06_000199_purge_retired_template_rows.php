<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $modified = DB::table('plantilla_silabo')->pluck('plantilla_modificada_en', 'plantilla_id');

        DB::unprepared(<<<'SQL'
            DELETE FROM definiciones_campo d
            WHERE NOT d.vigente_en_plantilla
              AND NOT EXISTS (SELECT 1 FROM valores_campo v WHERE v.definicion_campo_id = d.definicion_campo_id);
            DELETE FROM campos_plantilla c
            WHERE NOT c.vigente_en_plantilla
              AND NOT EXISTS (SELECT 1 FROM definiciones_campo d WHERE d.campo_plantilla_id = c.campo_plantilla_id);
            DELETE FROM secciones_plantilla s
            WHERE NOT s.vigente_en_plantilla
              AND NOT EXISTS (SELECT 1 FROM campos_plantilla c WHERE c.seccion_plantilla_id = s.seccion_plantilla_id);
            SQL);

        foreach ($modified as $templateId => $at) {
            DB::table('plantilla_silabo')->where('plantilla_id', $templateId)->update(['plantilla_modificada_en' => $at]);
        }
    }

    public function down(): void {}
};
