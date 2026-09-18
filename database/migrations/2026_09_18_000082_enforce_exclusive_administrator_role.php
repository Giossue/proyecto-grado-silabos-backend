<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM asignaciones_rol AS administracion
                    WHERE administracion.asignacion_rol_activa
                      AND administracion.rol = 'administrador'
                      AND EXISTS (
                          SELECT 1
                          FROM asignaciones_rol AS otra
                          WHERE otra.usuario_id = administracion.usuario_id
                            AND otra.asignacion_rol_activa
                            AND otra.rol <> 'administrador'
                      )
                ) THEN
                    RAISE EXCEPTION 'No se puede aplicar la exclusividad del administrador: existen cuentas con roles activos incompatibles';
                END IF;
            END
            $$;

            CREATE OR REPLACE FUNCTION comprobar_administrador_exclusivo() RETURNS trigger AS $$
            BEGIN
                IF NEW.asignacion_rol_activa
                   AND (
                       (
                           NEW.rol = 'administrador'
                           AND EXISTS (
                               SELECT 1
                               FROM asignaciones_rol AS existente
                               WHERE existente.usuario_id = NEW.usuario_id
                                 AND existente.asignacion_rol_activa
                                 AND existente.rol <> 'administrador'
                                 AND existente.id <> NEW.id
                           )
                       )
                       OR
                       (
                           NEW.rol <> 'administrador'
                           AND EXISTS (
                               SELECT 1
                               FROM asignaciones_rol AS existente
                               WHERE existente.usuario_id = NEW.usuario_id
                                 AND existente.asignacion_rol_activa
                                 AND existente.rol = 'administrador'
                                 AND existente.id <> NEW.id
                           )
                       )
                   ) THEN
                    RAISE EXCEPTION 'El rol Administrador es exclusivo' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER asignaciones_rol_administrador_exclusivo
            BEFORE INSERT OR UPDATE OF usuario_id, rol, asignacion_rol_activa
            ON asignaciones_rol
            FOR EACH ROW EXECUTE FUNCTION comprobar_administrador_exclusivo();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS asignaciones_rol_administrador_exclusivo ON asignaciones_rol;
            DROP FUNCTION IF EXISTS comprobar_administrador_exclusivo();
        SQL);
    }
};
