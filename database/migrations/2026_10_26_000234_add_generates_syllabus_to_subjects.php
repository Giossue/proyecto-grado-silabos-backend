<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asignaturas', fn (Blueprint $table) => $table->boolean('genera_silabo_asignatura')->default(true));
    }

    public function down(): void
    {
        Schema::table('asignaturas', fn (Blueprint $table) => $table->dropColumn('genera_silabo_asignatura'));
    }
};
