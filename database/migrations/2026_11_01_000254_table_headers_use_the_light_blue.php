<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = ['table_header_background' => '#4F81BD', 'table_header_text' => '#FFFFFF'];

    private const NEW = ['table_header_background' => '#C3D4EA', 'table_header_text' => '#000000'];

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    /**
     * @param  array<string, string>  $from
     * @param  array<string, string>  $to
     */
    private function swap(array $from, array $to): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla', 'plantilla_modificada_en']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            $colors = is_array($definition) ? ($definition['document']['colors'] ?? null) : null;
            if (! is_array($colors) || array_intersect_key(array_map('strtoupper', array_filter($colors, 'is_string')), $from) != $from) {
                continue;
            }
            $definition['document']['colors'] = array_replace($colors, $to);
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)->update([
                'definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['plantilla_modificada_en' => $template->plantilla_modificada_en]);
        }
    }
};
