<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->swap('call_number', 'format');
    }

    public function down(): void
    {
        $this->swap('format', 'call_number');
    }

    private function swap(string $from, string $to): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla', 'plantilla_modificada_en']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            $map = is_array($definition) ? ($definition['blocks']['bibliografia']['catalog']['map'] ?? null) : null;
            if (! is_array($map) || ($map[$from] ?? null) !== 'codigo' || array_key_exists($to, $map)) {
                continue;
            }
            unset($map[$from]);
            $map[$to] = 'codigo';
            $definition['blocks']['bibliografia']['catalog']['map'] = $map;
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['plantilla_modificada_en' => $template->plantilla_modificada_en]);
        }
    }
};
