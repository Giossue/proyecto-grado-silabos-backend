<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        DB::statement('DROP INDEX convocatorias_universidad_periodo_unico');
        DB::statement('CREATE UNIQUE INDEX convocatorias_universidad_periodo_unico ON convocatorias_universidad (periodo_academico_id)');
        DB::statement('ALTER TABLE convocatorias_universidad DROP CONSTRAINT convocatorias_universidad_eliminado_check');
        DB::statement('ALTER TABLE convocatorias_universidad DROP CONSTRAINT convocatorias_universidad_estado_check');
        DB::statement("ALTER TABLE convocatorias_universidad ADD CONSTRAINT convocatorias_universidad_estado_check CHECK (estado_convocatoria_universidad IN ('abierto', 'pausado', 'cerrado'))");
        Schema::table('convocatorias_universidad', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('eliminado_por');
            $table->dropColumn('eliminado_en');
        });
    }
};
