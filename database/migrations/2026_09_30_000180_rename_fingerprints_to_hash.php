<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLUMNS = [
        'invitaciones_activacion' => ['huella_activacion', 'hash_activacion'],
        'archivos' => ['huella_archivo', 'hash_archivo'],
        'firmas_documento' => ['huella_archivo', 'hash_archivo'],
        'revisiones_silabo' => ['huella_contenido', 'hash_contenido'],
        'aprobaciones' => ['huella_contenido', 'hash_contenido'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => [$old, $new]) {
            DB::statement("ALTER TABLE {$table} RENAME COLUMN {$old} TO {$new}");
        }
        $rows = DB::select(<<<'SQL'
            SELECT t.relname AS tabla, c.conname AS actual
            FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE n.nspname = 'public' AND c.conname LIKE '%huella%'
        SQL);
        foreach ($rows as $row) {
            $new = str_replace(['huella_activacion', 'huella_archivo', 'huella_contenido', 'huella'], ['hash_activacion', 'hash_archivo', 'hash_contenido', 'hash'], $row->actual);
            DB::statement(sprintf('ALTER TABLE %s RENAME CONSTRAINT "%s" TO "%s"', $row->tabla, $row->actual, $new));
        }
        foreach (DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND indexname LIKE '%huella%'") as $index) {
            $new = str_replace(['huella_activacion', 'huella_archivo', 'huella_contenido', 'huella'], ['hash_activacion', 'hash_archivo', 'hash_contenido', 'hash'], $index->indexname);
            DB::statement(sprintf('ALTER INDEX "%s" RENAME TO "%s"', $index->indexname, $new));
        }
    }

    public function down(): void
    {
        throw new LogicException('El cambio de huella a hash no se revierte: restaure el respaldo previo.');
    }
};
