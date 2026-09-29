<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carreras', function (Blueprint $table): void {
            $table->foreignUuid('campus_id')->nullable()->after('modalidad_id')
                ->constrained('campus')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carreras', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('campus_id');
        });
    }
};
