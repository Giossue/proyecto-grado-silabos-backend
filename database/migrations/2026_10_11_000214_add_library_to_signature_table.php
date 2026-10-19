<?php

use App\Modules\Configuration\Application\FixedContent;
use App\Modules\Configuration\Domain\FreeText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $fields = DB::table('definiciones_campo')
            ->where('clave_definicion_campo', 'firmas')
            ->where('tipo_definicion_campo', 'fijo')
            ->get(['definicion_campo_id', 'opciones_definicion_campo']);
        foreach ($fields as $field) {
            $options = json_decode((string) $field->opciones_definicion_campo, true);
            if (! is_array($options) || ! is_array($options['content'] ?? null)) {
                continue;
            }
            $text = FreeText::plainText($options['content']);
            if (! str_contains($text, 'Fecha de revisión:') || str_contains($text, 'Fecha de verificación:')) {
                continue;
            }
            $options['content'] = FixedContent::official('firmas');
            DB::table('definiciones_campo')
                ->where('definicion_campo_id', $field->definicion_campo_id)
                ->update(['opciones_definicion_campo' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void {}
};
