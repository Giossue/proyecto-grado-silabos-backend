<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE eventos_auditoria DROP COLUMN IF EXISTS correlacion_id');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE eventos_auditoria ADD COLUMN IF NOT EXISTS correlacion_id uuid NULL');
    }
};
