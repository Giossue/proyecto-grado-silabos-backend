<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valores_campo', function (Blueprint $table): void {
            $table->dropColumn('editable_docente_valor_campo');
        });
    }

    public function down(): void
    {
        Schema::table('valores_campo', function (Blueprint $table): void {
            $table->boolean('editable_docente_valor_campo')->default(false);
        });
    }
};
