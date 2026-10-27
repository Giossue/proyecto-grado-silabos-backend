<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones', function (Blueprint $table): void {
            $table->foreignUuid('periodo_academico_id')->nullable()
                ->constrained('periodos_academicos', 'periodo_academico_id')->nullOnDelete();
            $table->index(['usuario_id', 'periodo_academico_id']);
        });

        $syllabusPeriod = 'SELECT p.periodo_academico_id FROM silabos s
            JOIN convocatorias_carreras c ON c.convocatoria_carrera_id = s.convocatoria_carrera_id
            JOIN convocatorias_universidad p ON p.convocatoria_universidad_id = c.convocatoria_universidad_id';
        DB::statement("UPDATE notificaciones n SET periodo_academico_id = ({$syllabusPeriod} WHERE s.silabo_id = n.recurso_id)
            WHERE n.tipo_recurso = 'silabo'");
        DB::statement("UPDATE notificaciones n SET periodo_academico_id = ({$syllabusPeriod}
            JOIN revisiones_silabo r ON r.silabo_id = s.silabo_id
            JOIN exportaciones_silabo e ON e.revision_silabo_id = r.revision_silabo_id
            WHERE e.exportacion_silabo_id = n.recurso_id)
            WHERE n.tipo_recurso = 'artefacto_exportacion'");
        DB::statement("UPDATE notificaciones n SET periodo_academico_id = (SELECT p.periodo_academico_id FROM convocatorias_carreras c
            JOIN convocatorias_universidad p ON p.convocatoria_universidad_id = c.convocatoria_universidad_id
            WHERE c.convocatoria_carrera_id = n.recurso_id)
            WHERE n.tipo_recurso = 'convocatoria'");
        DB::statement("UPDATE notificaciones n SET periodo_academico_id = (SELECT p.periodo_academico_id FROM convocatorias_universidad p
            WHERE p.convocatoria_universidad_id = n.recurso_id)
            WHERE n.tipo_recurso = 'proceso_silabos'");
    }

    public function down(): void
    {
        Schema::table('notificaciones', function (Blueprint $table): void {
            $table->dropIndex(['usuario_id', 'periodo_academico_id']);
            $table->dropConstrainedForeignId('periodo_academico_id');
        });
    }
};
