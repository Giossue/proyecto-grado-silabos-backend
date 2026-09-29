<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('definiciones_campo', function (Blueprint $table) {
            $table->boolean('ia_coordinacion_configurable')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('definiciones_campo', function (Blueprint $table) {
            $table->dropColumn('ia_coordinacion_configurable');
        });
    }
};
