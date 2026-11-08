<?php

use App\Modules\Configuration\Application\OfficialTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cada unidad necesita al menos una semana para enviar. Solo se agrega esa regla a la
 * plantilla guardada: el resto de su bloque de unidades queda como esté.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rule = collect(OfficialTemplate::definition()->block('unidades')['rules'])->firstWhere('code', 'unidades_sin_semanas');
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            $rules = $definition['blocks']['unidades']['rules'] ?? null;
            if (! is_array($rules) || in_array('unidades_sin_semanas', array_column($rules, 'code'), true)) {
                continue;
            }
            $after = array_search('unidades_sin_nombre', array_column($rules, 'code'), true);
            array_splice($rules, $after === false ? count($rules) : $after + 1, 0, [$rule]);
            $definition['blocks']['unidades']['rules'] = $rules;
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void {}
};
