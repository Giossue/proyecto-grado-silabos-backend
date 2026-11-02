<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = ['institution' => 28, 'faculty' => 40];

    private const NEW = ['institution' => 36, 'faculty' => 36];

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    /**
     * Solo cambia las plantillas que todavía tienen las alturas del formato oficial anterior.
     *
     * @param  array<string, int>  $from
     * @param  array<string, int>  $to
     */
    private function swap(array $from, array $to): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla', 'plantilla_modificada_en']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            $logos = is_array($definition) ? ($definition['document']['header']['logos'] ?? null) : null;
            if (! is_array($logos) || array_column($logos, 'height_pt', 'source') != $from) {
                continue;
            }
            $definition['document']['header']['logos'] = array_map(
                fn (array $logo): array => [...$logo, 'height_pt' => $to[$logo['source']]],
                $logos,
            );
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)->update([
                'definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['plantilla_modificada_en' => $template->plantilla_modificada_en]);
        }
    }
};
