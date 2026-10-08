<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ayudas_pantalla', function ($table): void {
            $table->renameColumn('actualizado_en', 'editada_en_ayuda');
        });
    }

    public function down(): void
    {
        Schema::table('ayudas_pantalla', function ($table): void {
            $table->renameColumn('editada_en_ayuda', 'actualizado_en');
        });
    }
};
