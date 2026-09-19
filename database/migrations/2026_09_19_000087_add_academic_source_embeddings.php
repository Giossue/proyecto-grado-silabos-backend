<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('ALTER TABLE fragmentos_fuente_academica ADD COLUMN IF NOT EXISTS embedding vector(1536)');
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS fragmento_fuente_embedding_hnsw_idx
            ON fragmentos_fuente_academica
            USING hnsw (embedding vector_cosine_ops)
            WHERE embedding IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fragmento_fuente_embedding_hnsw_idx');
        DB::statement('ALTER TABLE fragmentos_fuente_academica DROP COLUMN IF EXISTS embedding');
    }
};
