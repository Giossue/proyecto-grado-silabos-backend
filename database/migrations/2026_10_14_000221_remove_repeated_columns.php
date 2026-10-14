<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-222: la clave, el título y la posición de `definiciones_campo` repetían a `campos_plantilla`, y
 * `envios_biblioteca.verificado_*` repetía a `aprobaciones`. No se revierte: la reversa exige
 * restaurar el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $distintos = DB::selectOne(<<<'SQL'
            SELECT count(*) AS total FROM definiciones_campo d
            JOIN campos_plantilla c ON c.campo_plantilla_id = d.campo_plantilla_id
            WHERE d.clave_definicion_campo <> c.clave_campo_plantilla OR d.etiqueta_definicion_campo <> c.titulo_campo_plantilla
        SQL);
        if ((int) $distintos->total > 0) {
            throw new RuntimeException('Hay campos cuya clave o título difiere entre campos_plantilla y definiciones_campo. Resuélvalo antes de migrar.');
        }
        $repetidos = DB::selectOne('SELECT count(*) AS total FROM (SELECT campo_plantilla_id FROM definiciones_campo GROUP BY campo_plantilla_id HAVING count(*) > 1) t');
        if ((int) $repetidos->total > 0) {
            throw new RuntimeException('Hay campos con más de una definición. Resuélvalo antes de migrar.');
        }
        $sinAprobar = DB::selectOne(<<<'SQL'
            SELECT count(*) AS total FROM envios_biblioteca e
            WHERE e.verificado_en IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM aprobaciones a WHERE a.revision_silabo_id = e.revision_silabo_id)
        SQL);
        if ((int) $sinAprobar->total > 0) {
            throw new RuntimeException('Hay verificaciones de biblioteca sin aprobación. Resuélvalas antes de migrar.');
        }

        DB::statement('ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_campo_unico UNIQUE (campo_plantilla_id)');
        DB::statement('DROP INDEX definiciones_campo_vigente_clave_unica');
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT definiciones_campo_campo_id_posicion_unica');
        DB::statement('ALTER TABLE definiciones_campo DROP COLUMN clave_definicion_campo, DROP COLUMN etiqueta_definicion_campo, DROP COLUMN posicion_definicion_campo');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_envio_biblioteca() RETURNS trigger AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                RAISE EXCEPTION 'El envío a biblioteca es inmutable' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('ALTER TABLE envios_biblioteca DROP COLUMN verificado_por, DROP COLUMN verificado_en');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-222.');
    }
};
