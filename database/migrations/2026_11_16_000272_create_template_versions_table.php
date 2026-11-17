<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versiones_plantilla', function (Blueprint $table): void {
            $table->uuid('version_plantilla_id')->primary();
            $table->uuid('plantilla_id');
            $table->integer('numero_version_plantilla');
            $table->jsonb('contenido_version_plantilla');
            $table->uuid('guardada_por')->nullable();
            $table->timestampTz('guardada_en', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('plantilla_id')->references('plantilla_id')->on('plantilla_silabo')->cascadeOnDelete();
            $table->foreign('guardada_por')->references('usuario_id')->on('usuarios')->restrictOnDelete();
            $table->unique(['plantilla_id', 'numero_version_plantilla']);
            $table->index('guardada_por');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE versiones_plantilla ADD CONSTRAINT versiones_plantilla_numero_check CHECK (numero_version_plantilla >= 1);
            ALTER TABLE versiones_plantilla ADD CONSTRAINT versiones_plantilla_contenido_check CHECK (jsonb_typeof(contenido_version_plantilla) = 'object');

            CREATE TRIGGER proteger_version_plantilla
            BEFORE UPDATE ON versiones_plantilla
            FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('versiones_plantilla');
    }
};
