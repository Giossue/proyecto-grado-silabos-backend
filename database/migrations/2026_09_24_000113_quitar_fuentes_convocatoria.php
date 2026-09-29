<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fuentes_convocatoria');
    }

    public function down(): void
    {
        if (Schema::hasTable('fuentes_convocatoria')) {
            return;
        }
        Schema::create('fuentes_convocatoria', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('convocatoria_id')->constrained('convocatorias_carreras')->cascadeOnDelete();
            $table->foreignUuid('fuente_academica_id')->constrained('fuentes_academicas');
            $table->unique(['convocatoria_id', 'fuente_academica_id']);
            $table->index('fuente_academica_id');
        });
    }
};
