<?php

use App\Modules\Operations\Application\ScreenHelpCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Los textos de ayuda viven en ScreenHelpCatalog; Administración ya no los edita.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ayudas_pantalla');
    }

    public function down(): void
    {
        Schema::create('ayudas_pantalla', function (Blueprint $table): void {
            $table->uuid('ayuda_pantalla_id')->primary();
            $table->string('pantalla', 160)->unique();
            $table->string('titulo_ayuda', 120);
            $table->text('contenido_ayuda');
            $table->boolean('activa')->default(true);
            $table->uuid('actualizado_por')->nullable()->index();
            $table->timestampTz('editada_en_ayuda', 0);

            $table->foreign('actualizado_por')->references('usuario_id')->on('usuarios')->nullOnDelete();
        });
        DB::statement("ALTER TABLE ayudas_pantalla ADD CONSTRAINT ayudas_pantalla_clave_check CHECK (pantalla ~ '^/[a-z0-9/:_-]*$')");

        $now = now();
        foreach (ScreenHelpCatalog::HELP as $screen => $help) {
            DB::table('ayudas_pantalla')->insert([
                'ayuda_pantalla_id' => (string) Str::uuid(),
                'pantalla' => $screen,
                'titulo_ayuda' => $help['title'],
                'contenido_ayuda' => $help['content'],
                'activa' => true,
                'editada_en_ayuda' => $now,
            ]);
        }
    }
};
