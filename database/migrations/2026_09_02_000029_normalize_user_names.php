<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE usuarios SET nombre = upper(regexp_replace(btrim(nombre), '\\s+', ' ', 'g'))");
    }

    public function down(): void {}
};
