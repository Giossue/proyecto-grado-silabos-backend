<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-223: `campos_plantilla.configuracion_campo_plantilla`, `secciones_plantilla.descripcion_seccion_plantilla`
 * y `definiciones_campo.ayuda_definicion_campo` no tenían datos ni función. No se revierte: la
 * reversa exige restaurar el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conDatos = DB::selectOne(<<<'SQL'
            SELECT
                (SELECT count(*) FROM campos_plantilla WHERE configuracion_campo_plantilla <> '[]'::jsonb AND configuracion_campo_plantilla <> '{}'::jsonb) AS configuracion,
                (SELECT count(*) FROM secciones_plantilla WHERE coalesce(descripcion_seccion_plantilla, '') <> '') AS descripcion,
                (SELECT count(*) FROM definiciones_campo WHERE coalesce(ayuda_definicion_campo, '') <> '') AS ayuda
        SQL);
        if ((int) $conDatos->configuracion + (int) $conDatos->descripcion + (int) $conDatos->ayuda > 0) {
            throw new RuntimeException('Hay configuración, descripciones o ayudas con contenido. Resuélvalo antes de migrar.');
        }

        DB::statement('ALTER TABLE campos_plantilla DROP COLUMN configuracion_campo_plantilla');
        DB::statement('ALTER TABLE secciones_plantilla DROP COLUMN descripcion_seccion_plantilla');
        DB::statement('ALTER TABLE definiciones_campo DROP COLUMN ayuda_definicion_campo');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-223.');
    }
};
