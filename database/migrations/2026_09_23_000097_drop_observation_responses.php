<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-120: solo Coordinación escribe observaciones; el docente corrige y reenvía. Las
 * respuestas del docente dejan de existir, y con ellas el estado intermedio
 * «respondida»: una observación está abierta o verificada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('observaciones_revision')
            ->where('estado_observacion_revision', 'respondida')
            ->update(['estado_observacion_revision' => 'abierta']);

        // Sus triggers se van con la tabla; la función de inmutabilidad era exclusiva de ella.
        DB::statement('DROP TABLE IF EXISTS respuestas_observacion');
        DB::statement('DROP FUNCTION IF EXISTS proteger_respuesta_enviada()');

        DB::statement('ALTER TABLE observaciones_revision DROP CONSTRAINT IF EXISTS observaciones_revision_estado_check');
        DB::statement(<<<'SQL'
            ALTER TABLE observaciones_revision
            ADD CONSTRAINT observaciones_revision_estado_check
            CHECK (estado_observacion_revision IN ('abierta', 'verificada'))
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('La eliminación de las respuestas del docente no admite reversión automática.');
    }
};
