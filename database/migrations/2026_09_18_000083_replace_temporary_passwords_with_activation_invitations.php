<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitaciones_activacion', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->char('huella_activacion', 64)->unique();
            $table->timestampTz('expira_en');
            $table->timestampTz('usada_en')->nullable();
            $table->timestampTz('invalidada_en')->nullable();
            $table->foreignUuid('emitida_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestampTz('creada_en')->useCurrent();
            $table->index(['usuario_id', 'expira_en']);
        });

        // Las cuentas que todavía dependían de la contraseña temporal deben volver a
        // activar su correo mediante el nuevo enlace. Las cuentas ya activadas no se
        // modifican.
        DB::table('usuarios')
            ->where('debe_cambiar_contrasena', true)
            ->update(['correo_verificado_en' => null]);

        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropColumn('debe_cambiar_contrasena');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->boolean('debe_cambiar_contrasena')->default(false);
        });

        Schema::dropIfExists('invitaciones_activacion');
    }
};
