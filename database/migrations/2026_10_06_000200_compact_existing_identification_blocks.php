<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las secciones de identificación institucional ya creadas pasan a ser compactas (letra de 9 pt y
 * margen de 1.5 cm en esa hoja) y sin el nombre del campo (el
 * subtítulo «1.1»), igual que las nuevas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('campos_plantilla')
            ->whereRaw("configuracion_campo_plantilla->>'content_type' = 'institutional'")
            ->get(['campo_plantilla_id', 'configuracion_campo_plantilla']);
        foreach ($rows as $row) {
            $configuration = json_decode($row->configuracion_campo_plantilla, true);
            $configuration['compact'] = true;
            DB::table('campos_plantilla')->where('campo_plantilla_id', $row->campo_plantilla_id)->update([
                'titulo_campo_plantilla' => '',
                'configuracion_campo_plantilla' => json_encode($configuration, JSON_UNESCAPED_UNICODE),
            ]);
        }

        DB::table('definiciones_campo')
            ->where('clave_definicion_campo', 'asignatura')
            ->where('origen_dato_definicion_campo', 'asignaturas')
            ->update(['etiqueta_definicion_campo' => '']);
    }

    public function down(): void
    {
        // El nombre y los anchos anteriores no se recuperan: son ajustes de formato.
    }
};
