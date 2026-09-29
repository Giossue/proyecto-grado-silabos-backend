<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modalidades', function (Blueprint $table): void {
            $table->boolean('combina_por_asignatura')->default(false)->after('nombre');
        });
        Schema::table('carreras', function (Blueprint $table): void {
            $table->foreignUuid('modalidad_id')->nullable()->after('facultad_id')
                ->constrained('modalidades')->restrictOnDelete();
        });
        Schema::table('asignaturas', function (Blueprint $table): void {
            $table->foreignUuid('modalidad_id')->nullable()->after('unidad_organizacion_curricular')
                ->constrained('modalidades')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asignaturas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('modalidad_id');
        });
        Schema::table('carreras', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('modalidad_id');
        });
        Schema::table('modalidades', function (Blueprint $table): void {
            $table->dropColumn('combina_por_asignatura');
        });
    }
};
