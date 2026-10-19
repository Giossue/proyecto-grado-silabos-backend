<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE secciones_plantilla AS s
            SET titulo_seccion_plantilla = n.posicion || '. ' || s.titulo_seccion_plantilla
            FROM (
                SELECT seccion_plantilla_id, row_number() OVER (PARTITION BY plantilla_id ORDER BY posicion_seccion_plantilla) AS posicion
                FROM secciones_plantilla
                WHERE vigente_en_plantilla = true
            ) AS n
            WHERE s.seccion_plantilla_id = n.seccion_plantilla_id
              AND s.titulo_seccion_plantilla !~ '^\s*[0-9]+[.)]'
        SQL);
    }

    public function down(): void
    {
        DB::statement("UPDATE secciones_plantilla SET titulo_seccion_plantilla = regexp_replace(titulo_seccion_plantilla, '^\\s*[0-9]+[.)]\\s*', '') WHERE vigente_en_plantilla = true");
    }
};
