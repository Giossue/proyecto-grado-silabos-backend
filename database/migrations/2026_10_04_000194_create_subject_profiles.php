<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fichas_asignatura', function (Blueprint $table): void {
            $table->uuid('ficha_asignatura_id')->primary();
            $table->foreignUuid('asignatura_id')->unique()->constrained('asignaturas', 'asignatura_id')->cascadeOnDelete();
            $table->text('descripcion')->nullable();
            $table->text('objetivo_general')->nullable();
            $table->jsonb('objetivos_especificos')->nullable();
            $table->jsonb('resultados_aprendizaje')->nullable();
            $table->jsonb('habilidades_blandas')->nullable();
            $table->jsonb('bibliografia_basica')->nullable();
            $table->jsonb('bibliografia_complementaria')->nullable();
            $table->jsonb('herramientas_tic')->nullable();
            $table->jsonb('herramientas_ia')->nullable();
            $table->text('relacion_perfil_egreso')->nullable();
        });

        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT definiciones_campo_heredado_check');
        DB::statement("ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_heredado_check CHECK (NOT heredado_definicion_campo OR (origen_dato_definicion_campo IS NOT NULL AND (NOT editable_docente_definicion_campo OR origen_dato_definicion_campo IN ('fichas_asignatura.bibliografia_basica', 'fichas_asignatura.bibliografia_complementaria'))))");

        $sources = [
            'descripcion' => 'descripcion',
            'objetivo_general' => 'objetivo_general',
            'objetivos_especificos' => 'objetivos_especificos',
            'resultados_aprendizaje' => 'resultados_aprendizaje',
            'habilidades_blandas' => 'habilidades_blandas',
            'herramientas_tic' => 'herramientas_tic',
            'contribucion_perfil' => 'relacion_perfil_egreso',
            'bibliografia' => 'bibliografia_basica',
            'bibliografia_complementaria' => 'bibliografia_complementaria',
        ];
        foreach ($sources as $key => $source) {
            DB::table('definiciones_campo')
                ->where('clave_definicion_campo', $key)
                ->where('tipo_definicion_campo', 'texto_libre')
                ->update([
                    'heredado_definicion_campo' => true,
                    'origen_dato_definicion_campo' => 'fichas_asignatura.'.$source,
                    'editable_docente_definicion_campo' => str_starts_with($source, 'bibliografia_'),
                ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-187.');
    }
};
