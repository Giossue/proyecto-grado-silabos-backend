<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversaciones_ia', function (Blueprint $table) {
            $table->renameColumn('created_at', 'iniciada_en');
            $table->dropColumn('updated_at');
        });
        Schema::table('mensajes_ia', function (Blueprint $table) {
            $table->renameColumn('created_at', 'enviado_en');
            $table->dropColumn('updated_at');
        });
        Schema::table('evidencias_mensaje_ia', function (Blueprint $table) {
            $table->renameColumn('created_at', 'fijada_en');
        });
    }

    public function down(): void
    {
        Schema::table('evidencias_mensaje_ia', function (Blueprint $table) {
            $table->renameColumn('fijada_en', 'created_at');
        });
        Schema::table('mensajes_ia', function (Blueprint $table) {
            $table->renameColumn('enviado_en', 'created_at');
            $table->timestampTz('updated_at')->nullable();
        });
        Schema::table('conversaciones_ia', function (Blueprint $table) {
            $table->renameColumn('iniciada_en', 'created_at');
            $table->timestampTz('updated_at')->nullable();
        });
    }
};
