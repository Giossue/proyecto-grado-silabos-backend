<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ayudas_pantalla', function (Blueprint $table): void {
            $table->uuid('ayuda_pantalla_id')->primary();
            $table->string('pantalla', 160)->unique();
            $table->string('titulo_ayuda', 120);
            $table->text('contenido_ayuda');
            $table->boolean('activa')->default(true);
            $table->uuid('actualizado_por')->nullable();
            $table->timestampTz('actualizado_en');

            $table->foreign('actualizado_por')->references('usuario_id')->on('usuarios')->nullOnDelete();
        });
        DB::statement("ALTER TABLE ayudas_pantalla ADD CONSTRAINT ayudas_pantalla_clave_check CHECK (pantalla ~ '^/[a-z0-9/:_-]*$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ayudas_pantalla');
    }
};
