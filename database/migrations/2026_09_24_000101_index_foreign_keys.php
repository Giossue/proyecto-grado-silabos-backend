<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<array{0: string, 1: string}> */
    private const FOREIGN_KEYS = [
        ['sesiones', 'user_id'],
        ['facultades', 'logo_objeto_id'],
        ['carreras', 'facultad_id'],
        ['asignaciones_rol', 'carrera_id'],
        ['requisitos_asignatura', 'requisito_id'],
        ['programaciones_asignatura', 'asignatura_id'],
        ['eventos_auditoria', 'asignacion_rol_id'],
        ['fuentes_academicas', 'objeto_pdf_id'],
        ['convocatorias_carreras', 'proceso_id'],
        ['fuentes_convocatoria', 'fuente_academica_id'],
        ['valores_campo', 'definicion_campo_id'],
        ['filas_repetibles', 'definicion_campo_id'],
        ['ejecuciones_validacion', 'ejecutado_por'],
        ['resultados_validacion', 'definicion_campo_id'],
        ['revisiones_silabo', 'enviado_por'],
        ['revisiones_silabo', 'reapertura_id'],
        ['revisiones_silabo', 'revision_anterior_id'],
        ['observaciones_revision', 'creado_por'],
        ['solicitudes_correccion', 'solicitado_por'],
        ['solicitud_correccion_observaciones', 'observacion_revision_id'],
        ['aprobaciones', 'aprobado_por'],
        ['reaperturas', 'reabierto_por'],
        ['transiciones_silabo', 'actor_usuario_id'],
        ['transiciones_silabo', 'asignacion_rol_id'],
        ['transiciones_silabo', 'revision_silabo_id'],
        ['objetos_almacenados', 'carrera_id'],
        ['objetos_almacenados', 'propietario_usuario_id'],
        ['artefactos_exportacion', 'asignacion_rol_id'],
        ['artefactos_exportacion', 'solicitado_por'],
        ['ejecuciones_ia', 'asignacion_rol_id'],
        ['ejecuciones_ia', 'definicion_campo_id'],
        ['ejecuciones_ia', 'solicitado_por'],
        ['evidencias_ia', 'definicion_campo_id'],
        ['evidencias_ia', 'ejecucion_ia_id'],
        ['evidencias_ia', 'fragmento_fuente_academica_id'],
        ['evidencias_ia', 'fuente_academica_id'],
        ['recomendaciones_ia', 'definicion_campo_id'],
        ['recomendacion_evidencias_ia', 'evidencia_ia_id'],
        ['retroalimentacion_ia', 'asignacion_rol_id'],
        ['retroalimentacion_ia', 'usuario_id'],
        ['conversaciones_ia', 'usuario_id'],
        ['mensajes_ia', 'respuesta_a_id'],
        ['evidencias_mensaje_ia', 'definicion_campo_id'],
        ['evidencias_mensaje_ia', 'fuente_academica_id'],
        ['citas_mensaje_ia', 'evidencia_mensaje_ia_id'],
        ['revisiones_ia_silabo', 'asignacion_rol_id'],
        ['revisiones_ia_silabo', 'solicitado_por'],
        ['invitaciones_activacion', 'emitida_por_usuario_id'],
    ];

    public function up(): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $column]) {
            DB::statement(sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (%s)', $this->name($table, $column), $table, $column));
        }
    }

    public function down(): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $column]) {
            DB::statement(sprintf('DROP INDEX IF EXISTS %s', $this->name($table, $column)));
        }
    }

    private function name(string $table, string $column): string
    {
        return substr("{$table}_{$column}_index", 0, 63);
    }
};
