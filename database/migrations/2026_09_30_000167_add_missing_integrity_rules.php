<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODALITIES = "'presencial', 'semipresencial', 'en_linea', 'a_distancia', 'hibrida'";

    public function up(): void
    {
        DB::statement('CREATE TRIGGER proteger_firma_documento BEFORE DELETE OR UPDATE ON firmas_documento FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()');

        DB::statement('ALTER TABLE carreras ADD CONSTRAINT carreras_modalidad_check CHECK (modalidad_carrera IN ('.self::MODALITIES.'))');
        DB::statement('ALTER TABLE asignaturas ADD CONSTRAINT asignaturas_modalidad_check CHECK (modalidad_asignatura IS NULL OR modalidad_asignatura IN ('.self::MODALITIES.'))');

        DB::statement("ALTER TABLE requisitos_asignatura ADD CONSTRAINT requisitos_asignatura_tipo_check CHECK (tipo_requisito_asignatura IN ('prerrequisito', 'correquisito'))");
        DB::statement('ALTER TABLE requisitos_asignatura DROP CONSTRAINT requisitos_asignatura_asignatura_id_requisito_id_tipo_requisito');
        DB::statement('ALTER TABLE requisitos_asignatura ADD CONSTRAINT requisitos_asignatura_par_unico UNIQUE (asignatura_id, requisito_id)');

        DB::statement('ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_heredado_check CHECK (NOT heredado OR (origen_maestro IS NOT NULL AND NOT editable_docente))');

        DB::statement('ALTER TABLE asignaciones_rol ALTER COLUMN asignado_en SET DEFAULT CURRENT_TIMESTAMP');
        DB::statement('UPDATE asignaciones_rol SET asignado_en = CURRENT_TIMESTAMP WHERE asignado_en IS NULL');
        DB::statement('ALTER TABLE asignaciones_rol ALTER COLUMN asignado_en SET NOT NULL');
        DB::statement('ALTER TABLE asignaciones_paralelo ALTER COLUMN asignado_en SET DEFAULT CURRENT_TIMESTAMP');
        DB::statement('UPDATE asignaciones_paralelo SET asignado_en = CURRENT_TIMESTAMP WHERE asignado_en IS NULL');
        DB::statement('ALTER TABLE asignaciones_paralelo ALTER COLUMN asignado_en SET NOT NULL');

        DB::statement('ALTER TABLE objetos_almacenados DROP CONSTRAINT objetos_almacenados_estado_check');
        DB::statement("ALTER TABLE objetos_almacenados ADD CONSTRAINT objetos_almacenados_estado_check CHECK (estado_objeto_almacenado = 'activo')");
        DB::statement('ALTER TABLE eventos_auditoria DROP CONSTRAINT eventos_auditoria_resultado_check');
        DB::statement("ALTER TABLE eventos_auditoria ADD CONSTRAINT eventos_auditoria_resultado_check CHECK (resultado_evento_auditoria IN ('exito', 'fallido'))");
    }

    public function down(): void
    {
        throw new LogicException('Las reglas de integridad de I-168 no se revierten: restaure el respaldo previo.');
    }
};
