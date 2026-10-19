<?php

use App\Modules\Configuration\Application\FixedContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONTENT = ['indicadores_evaluacion' => 'indicadores', 'componentes_evaluacion' => 'escala', 'firmas' => 'firmas'];

    public function up(): void
    {
        foreach (self::CONTENT as $key => $official) {
            $fields = DB::table('definiciones_campo')
                ->where('clave_definicion_campo', $key)
                ->where('tipo_definicion_campo', 'fijo')
                ->get(['definicion_campo_id', 'opciones_definicion_campo']);
            foreach ($fields as $field) {
                $options = json_decode((string) $field->opciones_definicion_campo, true);
                if (! is_array($options) || ! is_array($options['content'] ?? null)) {
                    continue;
                }
                if (str_contains(json_encode($options['content'], JSON_THROW_ON_ERROR), 'backgroundColor')) {
                    continue;
                }
                $options['content'] = FixedContent::official($official);
                DB::table('definiciones_campo')
                    ->where('definicion_campo_id', $field->definicion_campo_id)
                    ->update(['opciones_definicion_campo' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            }
        }
    }

    public function down(): void {}
};
