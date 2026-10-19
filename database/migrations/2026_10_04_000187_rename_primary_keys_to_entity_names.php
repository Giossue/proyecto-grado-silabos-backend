<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = [
        'aprobaciones' => 'aprobacion_id',
        'archivos' => 'archivo_id',
        'asignaciones_paralelo' => 'asignacion_paralelo_id',
        'asignaciones_rol' => 'asignacion_rol_id',
        'asignaturas' => 'asignatura_id',
        'asignaturas_ofertadas' => 'asignatura_ofertada_id',
        'campos_plantilla' => 'campo_plantilla_id',
        'carreras' => 'carrera_id',
        'convocatorias_carreras' => 'convocatoria_carrera_id',
        'convocatorias_universidad' => 'convocatoria_universidad_id',
        'definiciones_campo' => 'definicion_campo_id',
        'envios_biblioteca' => 'envio_biblioteca_id',
        'eventos_auditoria' => 'evento_auditoria_id',
        'exportaciones_silabo' => 'exportacion_silabo_id',
        'facultades' => 'facultad_id',
        'firmas_documento' => 'firma_documento_id',
        'invitaciones_activacion' => 'invitacion_activacion_id',
        'mensajes_ia' => 'mensaje_ia_id',
        'notificaciones' => 'notificacion_id',
        'paralelos' => 'paralelo_id',
        'periodos_academicos' => 'periodo_academico_id',
        'plantilla_silabo' => 'plantilla_id',
        'requisitos_asignatura' => 'requisito_asignatura_id',
        'revisiones_coordinacion' => 'revision_coordinacion_id',
        'revisiones_silabo' => 'revision_silabo_id',
        'secciones_plantilla' => 'seccion_plantilla_id',
        'silabos' => 'silabo_id',
        'trabajos_en_cola' => 'trabajo_id',
        'usuarios' => 'usuario_id',
        'valores_campo' => 'valor_campo_id',
    ];

    public function up(): void
    {
        foreach (self::KEYS as $table => $key) {
            DB::statement("ALTER TABLE {$table} RENAME COLUMN id TO {$key}");
        }

        DB::statement(<<<'SQL'
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
                                 AND existente.asignacion_rol_id <> NEW.asignacion_rol_id
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
                                 AND existente.asignacion_rol_id <> NEW.asignacion_rol_id
                           )
                       )
                   ) THEN
                    RAISE EXCEPTION 'El rol Administrador es exclusivo' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION comprobar_coordinacion_activa_unica() RETURNS trigger AS $$
            BEGIN
                IF NEW.asignacion_rol_activa
                   AND NEW.carrera_id IS NOT NULL
                   AND NEW.rol = 'coordinador'
                   AND EXISTS (
                       SELECT 1
                       FROM asignaciones_rol AS existente
                       INNER JOIN usuarios AS usuario_existente ON usuario_existente.usuario_id = existente.usuario_id
                       WHERE existente.carrera_id = NEW.carrera_id
                         AND existente.asignacion_rol_activa
                         AND usuario_existente.usuario_activo
                         AND existente.rol = 'coordinador'
                         AND existente.asignacion_rol_id <> NEW.asignacion_rol_id
                   ) THEN
                    RAISE EXCEPTION 'La carrera ya tiene una coordinación activa' USING ERRCODE = '23505';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION impedir_cambio_periodo_proceso_con_convocatorias() RETURNS trigger AS $$
            BEGIN
                IF NEW.periodo_academico_id IS DISTINCT FROM OLD.periodo_academico_id
                   AND EXISTS (SELECT 1 FROM convocatorias_carreras WHERE convocatoria_universidad_id = OLD.convocatoria_universidad_id) THEN
                    RAISE EXCEPTION 'No se puede cambiar el período de una convocatoria universitaria con convocatorias de carrera.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_mensaje_ia() RETURNS trigger AS $$
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
                IF NEW.mensaje_ia_id IS DISTINCT FROM OLD.mensaje_ia_id
                   OR NEW.silabo_id IS DISTINCT FROM OLD.silabo_id
                   OR NEW.usuario_id IS DISTINCT FROM OLD.usuario_id
                   OR NEW.enviado_en IS DISTINCT FROM OLD.enviado_en
                   OR NEW.contenido_mensaje -> 'tipo' IS DISTINCT FROM OLD.contenido_mensaje -> 'tipo'
                   OR NEW.contenido_mensaje -> 'entrada' IS DISTINCT FROM OLD.contenido_mensaje -> 'entrada' THEN
                    RAISE EXCEPTION 'La entrada fijada de un mensaje de IA es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_condicion_definicion() RETURNS trigger AS $$
            BEGIN
                IF NEW.condicion_definicion_campo IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM definiciones_campo d
                    WHERE d.plantilla_id = NEW.plantilla_id
                      AND d.clave_definicion_campo = NEW.condicion_definicion_campo ->> 'key'
                      AND d.definicion_campo_id <> NEW.definicion_campo_id
                ) THEN
                    RAISE EXCEPTION 'La condición apunta a un campo que no existe en la plantilla' USING ERRCODE = '23503';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_relaciones_revision() RETURNS trigger AS $$
            DECLARE
                silabo_relacionado uuid;
                numero_anterior integer;
            BEGIN
                IF NEW.revision_anterior_id IS NOT NULL THEN
                    SELECT silabo_id, numero_revision
                    INTO silabo_relacionado, numero_anterior
                    FROM revisiones_silabo
                    WHERE revision_silabo_id = NEW.revision_anterior_id;
                    IF silabo_relacionado IS DISTINCT FROM NEW.silabo_id
                       OR NEW.numero_revision <> numero_anterior + 1 THEN
                        RAISE EXCEPTION 'La revisión anterior no pertenece a la secuencia del sílabo' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.numero_revision <> 1 THEN
                    RAISE EXCEPTION 'La primera revisión debe tener número 1' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_relaciones_flujo() RETURNS trigger AS $$
            DECLARE
                silabo_relacionado uuid;
            BEGIN
                IF TG_TABLE_NAME = 'aprobaciones' THEN
                    SELECT silabo_id INTO silabo_relacionado FROM revisiones_silabo WHERE revision_silabo_id = NEW.revision_silabo_id;
                    IF EXISTS (
                        SELECT 1 FROM aprobaciones a
                        JOIN revisiones_silabo r ON r.revision_silabo_id = a.revision_silabo_id
                        WHERE r.silabo_id = silabo_relacionado
                          AND a.clave_solicitud = NEW.clave_solicitud
                          AND a.aprobacion_id <> NEW.aprobacion_id
                    ) THEN
                        RAISE EXCEPTION 'La clave de solicitud de la aprobación ya se usó en el sílabo' USING ERRCODE = '23505';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-179.');
    }
};
