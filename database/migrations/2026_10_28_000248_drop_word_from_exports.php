<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $wordIds = DB::table('exportaciones_silabo')->whereNotNull('archivo_word_id')->pluck('archivo_word_id')->all();
        $files = DB::table('archivos')->whereIn('archivo_id', $wordIds)->get(['disco', 'ruta_archivo']);

        DB::statement('ALTER TABLE exportaciones_silabo DROP COLUMN archivo_word_id');
        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        DB::table('archivos')->whereIn('archivo_id', $wordIds)->delete();
        DB::statement("SET LOCAL app.reinicio_institucional = 'off'");

        DB::afterCommit(function () use ($files): void {
            foreach ($files as $file) {
                Storage::disk($file->disco)->delete($file->ruta_archivo);
            }
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE exportaciones_silabo ADD COLUMN archivo_word_id uuid NULL');
        DB::statement('ALTER TABLE exportaciones_silabo ADD CONSTRAINT exportaciones_silabo_archivo_word_id_unique UNIQUE (archivo_word_id)');
        DB::statement('ALTER TABLE exportaciones_silabo ADD CONSTRAINT exportaciones_silabo_archivo_word_id_foreign FOREIGN KEY (archivo_word_id) REFERENCES archivos (archivo_id) ON DELETE RESTRICT');
    }
};
