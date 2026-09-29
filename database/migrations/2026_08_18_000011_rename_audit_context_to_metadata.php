<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE eventos_auditoria RENAME COLUMN contexto TO metadatos');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE eventos_auditoria RENAME COLUMN metadatos TO contexto');
    }
};
