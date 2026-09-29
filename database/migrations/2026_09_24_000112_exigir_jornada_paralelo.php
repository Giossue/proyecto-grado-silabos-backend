<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('paralelos')->whereNull('jornada_paralelo')->update(['jornada_paralelo' => 'matutina']);
            DB::statement('ALTER TABLE paralelos ALTER COLUMN jornada_paralelo SET NOT NULL');
            DB::statement('ALTER TABLE paralelos DROP CONSTRAINT IF EXISTS paralelos_jornada_check');
            DB::statement("ALTER TABLE paralelos ADD CONSTRAINT paralelos_jornada_check CHECK (jornada_paralelo IN ('matutina', 'vespertina', 'nocturna'))");
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE paralelos DROP CONSTRAINT IF EXISTS paralelos_jornada_check');
        DB::statement("ALTER TABLE paralelos ADD CONSTRAINT paralelos_jornada_check CHECK (jornada_paralelo IS NULL OR jornada_paralelo IN ('matutina', 'vespertina', 'nocturna'))");
        DB::statement('ALTER TABLE paralelos ALTER COLUMN jornada_paralelo DROP NOT NULL');
    }
};
