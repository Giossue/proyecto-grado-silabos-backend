<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mensajes_ia ADD COLUMN silabo_id uuid, ADD COLUMN usuario_id uuid');
        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        DB::statement("UPDATE mensajes_ia SET silabo_id = split_part(conversacion_id, ':', 2)::uuid, usuario_id = split_part(conversacion_id, ':', 4)::uuid");
        DB::statement('DELETE FROM mensajes_ia m WHERE NOT EXISTS (SELECT 1 FROM silabos s WHERE s.id = m.silabo_id) OR NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.id = m.usuario_id)');
        DB::statement('RESET app.reinicio_institucional');

        DB::statement('DROP TRIGGER IF EXISTS eliminar_mensajes_ia_de_silabo ON silabos');
        DB::statement('DROP FUNCTION IF EXISTS eliminar_mensajes_ia_de_silabo()');
        DB::statement('DROP INDEX IF EXISTS mensajes_ia_conversacion_idx');
        DB::statement('DROP INDEX IF EXISTS mensajes_ia_mensaje_idempotencia_unica');
        DB::statement('ALTER TABLE mensajes_ia DROP COLUMN conversacion_id');

        DB::statement('ALTER TABLE mensajes_ia ALTER COLUMN silabo_id SET NOT NULL, ALTER COLUMN usuario_id SET NOT NULL');
        DB::statement('ALTER TABLE mensajes_ia ADD CONSTRAINT mensajes_ia_silabo_id_foreign FOREIGN KEY (silabo_id) REFERENCES silabos (id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE mensajes_ia ADD CONSTRAINT mensajes_ia_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX mensajes_ia_conversacion_index ON mensajes_ia (silabo_id, usuario_id, enviado_en)');
        DB::statement('CREATE INDEX mensajes_ia_usuario_id_index ON mensajes_ia (usuario_id)');
        DB::statement("CREATE UNIQUE INDEX mensajes_ia_mensaje_idempotencia_unica ON mensajes_ia (silabo_id, usuario_id, ((contenido_mensaje -> 'entrada') ->> 'clave_idempotencia')) WHERE contenido_mensaje ->> 'tipo' = 'mensaje' AND contenido_mensaje ->> 'rol' = 'usuario'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_mensaje_ia() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'El historial de IA no puede eliminarse' USING ERRCODE = '23514';
                END IF;
                IF OLD.contenido_mensaje ->> 'estado' NOT IN ('pendiente', 'en_ejecucion') THEN
                    RAISE EXCEPTION 'Un mensaje de IA terminal es inmutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id
                   OR NEW.silabo_id IS DISTINCT FROM OLD.silabo_id
                   OR NEW.usuario_id IS DISTINCT FROM OLD.usuario_id
                   OR NEW.enviado_en IS DISTINCT FROM OLD.enviado_en
                   OR NEW.contenido_mensaje -> 'tipo' IS DISTINCT FROM OLD.contenido_mensaje -> 'tipo'
                   OR NEW.contenido_mensaje -> 'entrada' IS DISTINCT FROM OLD.contenido_mensaje -> 'entrada' THEN
                    RAISE EXCEPTION 'La entrada fijada de un mensaje de IA es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        throw new LogicException('La separación de la conversación de IA no se revierte: restaure el respaldo previo.');
    }
};
