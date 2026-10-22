<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->resolveHiddenProcesses();

        DB::statement('ALTER TABLE convocatorias_universidad DROP CONSTRAINT convocatorias_universidad_eliminado_check');
        DB::statement('ALTER TABLE convocatorias_universidad DROP CONSTRAINT convocatorias_universidad_estado_check');
        DB::statement("ALTER TABLE convocatorias_universidad ADD CONSTRAINT convocatorias_universidad_estado_check CHECK (estado_convocatoria_universidad IN ('abierto', 'pausado', 'cerrado'))");

        Schema::table('convocatorias_universidad', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('eliminado_por');
            $table->dropColumn('eliminado_en');
        });

        DB::statement('DROP INDEX convocatorias_universidad_periodo_unico');
        DB::statement('CREATE UNIQUE INDEX convocatorias_universidad_periodo_unico ON convocatorias_universidad (periodo_academico_id)');
    }

    public function down(): void
    {
        Schema::table('convocatorias_universidad', function (Blueprint $table): void {
            $table->timestampTz('eliminado_en')->nullable();
            $table->foreignUuid('eliminado_por')->nullable()->constrained('usuarios', 'usuario_id')->nullOnDelete();
        });

        DB::statement('ALTER TABLE convocatorias_universidad DROP CONSTRAINT convocatorias_universidad_estado_check');
        DB::statement("ALTER TABLE convocatorias_universidad ADD CONSTRAINT convocatorias_universidad_estado_check CHECK (estado_convocatoria_universidad IN ('abierto', 'pausado', 'cerrado', 'eliminado'))");
        DB::statement("ALTER TABLE convocatorias_universidad ADD CONSTRAINT convocatorias_universidad_eliminado_check CHECK ((estado_convocatoria_universidad = 'eliminado') = (eliminado_en IS NOT NULL))");

        DB::statement('DROP INDEX convocatorias_universidad_periodo_unico');
        DB::statement("CREATE UNIQUE INDEX convocatorias_universidad_periodo_unico ON convocatorias_universidad (periodo_academico_id) WHERE estado_convocatoria_universidad <> 'eliminado'");
    }

    private function resolveHiddenProcesses(): void
    {
        $hidden = DB::table('convocatorias_universidad')
            ->where('estado_convocatoria_universidad', 'eliminado')
            ->get(['convocatoria_universidad_id', 'periodo_academico_id']);

        foreach ($hidden as $process) {
            $convocationIds = DB::table('convocatorias_carreras')
                ->where('convocatoria_universidad_id', $process->convocatoria_universidad_id)
                ->pluck('convocatoria_carrera_id')
                ->all();
            $syllabusIds = DB::table('silabos')
                ->whereIn('convocatoria_carrera_id', $convocationIds)
                ->pluck('silabo_id')
                ->all();

            $delivered = $syllabusIds !== [] && DB::table('revisiones_silabo')
                ->whereIn('silabo_id', $syllabusIds)
                ->exists();

            if ($delivered) {
                DB::table('convocatorias_universidad')
                    ->where('convocatoria_universidad_id', $process->convocatoria_universidad_id)
                    ->update([
                        'estado_convocatoria_universidad' => 'cerrado',
                        'eliminado_en' => null,
                    ]);

                continue;
            }

            DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
            DB::table('notificaciones')->whereIn('recurso_id', $syllabusIds)->delete();
            DB::statement("SET LOCAL app.reinicio_institucional = 'off'");
            DB::table('silabos')->whereIn('silabo_id', $syllabusIds)->delete();
            DB::table('convocatorias_carreras')->whereIn('convocatoria_carrera_id', $convocationIds)->delete();
            DB::table('convocatorias_universidad')
                ->where('convocatoria_universidad_id', $process->convocatoria_universidad_id)
                ->delete();
        }
    }
};
