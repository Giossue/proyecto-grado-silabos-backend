<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE bloques_plantilla RENAME TO campos_plantilla;
            ALTER TABLE campos_plantilla RENAME COLUMN clave TO clave_campo_plantilla;
            ALTER TABLE campos_plantilla RENAME COLUMN titulo TO titulo_campo_plantilla;
            ALTER TABLE campos_plantilla RENAME COLUMN posicion TO posicion_campo_plantilla;
            ALTER TABLE campos_plantilla RENAME COLUMN configuracion TO configuracion_campo_plantilla;
            ALTER INDEX bloques_plantilla_seccion_plantilla_id_posicion_index RENAME TO campos_plantilla_seccion_plantilla_id_posicion_index;
            ALTER TRIGGER tocar_revision_por_bloque ON campos_plantilla RENAME TO tocar_revision_por_campo;
            ALTER TABLE definiciones_campo RENAME COLUMN bloque_plantilla_id TO campo_plantilla_id;
            ALTER TABLE definiciones_campo RENAME CONSTRAINT definiciones_campo_bloque_id_posicion_unica TO definiciones_campo_campo_id_posicion_unica;
            SQL);
        $this->renameConstraints('bloque', 'campo');
        DB::unprepared(<<<'SQL'
            ALTER TABLE secciones_plantilla RENAME COLUMN clave TO clave_seccion_plantilla;
            ALTER TABLE secciones_plantilla RENAME COLUMN titulo TO titulo_seccion_plantilla;
            ALTER TABLE secciones_plantilla RENAME COLUMN descripcion TO descripcion_seccion_plantilla;
            ALTER TABLE secciones_plantilla RENAME COLUMN posicion TO posicion_seccion_plantilla;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE secciones_plantilla RENAME COLUMN posicion_seccion_plantilla TO posicion;
            ALTER TABLE secciones_plantilla RENAME COLUMN descripcion_seccion_plantilla TO descripcion;
            ALTER TABLE secciones_plantilla RENAME COLUMN titulo_seccion_plantilla TO titulo;
            ALTER TABLE secciones_plantilla RENAME COLUMN clave_seccion_plantilla TO clave;
            ALTER TABLE definiciones_campo RENAME CONSTRAINT definiciones_campo_campo_id_posicion_unica TO definiciones_campo_bloque_id_posicion_unica;
            ALTER TABLE definiciones_campo RENAME COLUMN campo_plantilla_id TO bloque_plantilla_id;
            ALTER TRIGGER tocar_revision_por_campo ON campos_plantilla RENAME TO tocar_revision_por_bloque;
            ALTER INDEX campos_plantilla_seccion_plantilla_id_posicion_index RENAME TO bloques_plantilla_seccion_plantilla_id_posicion_index;
            SQL);
        $this->renameConstraints('campo', 'bloque');
        DB::unprepared(<<<'SQL'
            ALTER TABLE campos_plantilla RENAME COLUMN configuracion_campo_plantilla TO configuracion;
            ALTER TABLE campos_plantilla RENAME COLUMN posicion_campo_plantilla TO posicion;
            ALTER TABLE campos_plantilla RENAME COLUMN titulo_campo_plantilla TO titulo;
            ALTER TABLE campos_plantilla RENAME COLUMN clave_campo_plantilla TO clave;
            ALTER TABLE campos_plantilla RENAME TO bloques_plantilla;
            SQL);
    }

    private function renameConstraints(string $from, string $to): void
    {
        foreach (DB::select(
            "SELECT conrelid::regclass::text AS tabla, conname FROM pg_constraint
             WHERE conrelid IN ('campos_plantilla'::regclass, 'definiciones_campo'::regclass)
               AND (conname LIKE ? OR conname LIKE ?)",
            ["{$from}s_plantilla%", "definiciones_campo_{$from}_plantilla%"],
        ) as $constraint) {
            $renamed = str_replace(["{$from}s_plantilla", "_{$from}_plantilla"], ["{$to}s_plantilla", "_{$to}_plantilla"], $constraint->conname);
            DB::statement(sprintf('ALTER TABLE %s RENAME CONSTRAINT %s TO %s', $constraint->tabla, $constraint->conname, $renamed));
        }
    }
};
