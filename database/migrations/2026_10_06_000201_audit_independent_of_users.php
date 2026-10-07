<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La auditoría deja de depender de la cuenta: sin llaves hacia usuarios ni asignaciones de rol, y cada evento
 * nuevo guarda quién actuó (nombre, rol y carrera) tal como era en ese momento. Los eventos anteriores no se
 * tocan (la tabla es append-only); se siguen resolviendo por id mientras la cuenta exista.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE eventos_auditoria
                DROP CONSTRAINT eventos_auditoria_usuario_id_foreign,
                DROP CONSTRAINT eventos_auditoria_asignacion_rol_id_foreign,
                ADD COLUMN actor_nombre varchar(255),
                ADD COLUMN actor_rol varchar(120),
                ADD COLUMN actor_carrera varchar(255);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE eventos_auditoria
                DROP COLUMN actor_nombre,
                DROP COLUMN actor_rol,
                DROP COLUMN actor_carrera,
                ADD CONSTRAINT eventos_auditoria_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES usuarios (usuario_id) ON DELETE RESTRICT,
                ADD CONSTRAINT eventos_auditoria_asignacion_rol_id_foreign FOREIGN KEY (asignacion_rol_id) REFERENCES asignaciones_rol (asignacion_rol_id) ON DELETE RESTRICT;
            SQL);
    }
};
