<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RULE_KEYS = ['rules', 'required', 'max_length', 'min_rows', 'max_rows'];

    public function up(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasColumn('definiciones_campo', 'reglas_definicion_campo')) {
                Schema::table('definiciones_campo', fn ($table) => $table->dropColumn('reglas_definicion_campo'));
            }

            foreach (DB::table('plantilla_silabo')->lockForUpdate()->get(['id', 'documento_plantilla']) as $template) {
                $document = json_decode((string) $template->documento_plantilla, true);
                if (! is_array($document)) {
                    continue;
                }
                $changed = false;
                foreach ($document['secciones'] ?? [] as $s => $section) {
                    foreach ($section['bloques'] ?? [] as $b => $block) {
                        $configuration = $block['configuracion'] ?? null;
                        if (! is_array($configuration)) {
                            continue;
                        }
                        $clean = array_diff_key($configuration, array_flip(self::RULE_KEYS));
                        if (is_array($clean['detached_fields'] ?? null)) {
                            $clean['detached_fields'] = array_map(
                                fn (mixed $saved): mixed => is_array($saved) ? array_diff_key($saved, array_flip(self::RULE_KEYS)) : $saved,
                                $clean['detached_fields'],
                            );
                        }
                        if ($clean !== $configuration) {
                            $document['secciones'][$s]['bloques'][$b]['configuracion'] = $clean;
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    DB::table('plantilla_silabo')->where('id', $template->id)->update([
                        'documento_plantilla' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('definiciones_campo', 'reglas_definicion_campo')) {
            Schema::table('definiciones_campo', fn ($table) => $table->jsonb('reglas_definicion_campo')->nullable());
        }
    }
};
