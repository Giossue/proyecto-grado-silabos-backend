<?php

use App\Modules\Configuration\Domain\Blocks\ValueSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MULTILINE = [
        'identificacion' => [['disability', 'properties', 'description']],
        'unidades' => [
            ['units', 'items', 'properties', 'resultado'],
            ['units', 'items', 'properties', 'semanas', 'items', 'properties', 'contenidos'],
            ['units', 'items', 'properties', 'semanas', 'items', 'properties', 'acd'],
            ['units', 'items', 'properties', 'semanas', 'items', 'properties', 'ape'],
            ['units', 'items', 'properties', 'semanas', 'items', 'properties', 'aa'],
            ['units', 'items', 'properties', 'semanas', 'items', 'properties', 'evaluacion'],
        ],
    ];

    public function up(): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            if (! is_array($definition) || ! is_array($definition['blocks'] ?? null)) {
                continue;
            }
            foreach ($definition['blocks'] as $kind => $block) {
                foreach (['props', 'value'] as $part) {
                    if (is_array($block[$part] ?? null)) {
                        $definition['blocks'][$kind][$part] = ValueSchema::upgradeAll($block[$part]);
                    }
                }
                foreach (self::MULTILINE[$kind] ?? [] as $path) {
                    $schema = &$definition['blocks'][$kind]['value'];
                    foreach ($path as $segment) {
                        if (! is_array($schema[$segment] ?? null)) {
                            unset($schema);

                            continue 2;
                        }
                        $schema = &$schema[$segment];
                    }
                    if (($schema['type'] ?? null) === 'string') {
                        $schema['multiline'] = true;
                    }
                    unset($schema);
                }
            }
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void {}
};
