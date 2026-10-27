<?php

use App\Modules\Configuration\Application\OfficialTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $official = OfficialTemplate::definition();
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            if (! is_array($definition) || ! isset($definition['blocks']['unidades'])) {
                continue;
            }
            $definition['blocks']['unidades'] = $official->block('unidades');
            $definition['variables']['semanas_periodo'] = $official->variables()['semanas_periodo'];
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void {}
};
