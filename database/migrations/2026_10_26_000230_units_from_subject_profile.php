<?php

use App\Modules\Configuration\Application\Actions\ProtectTemplateResponses;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Validation\ValidationException;

/**
 * Lleva la plantilla activa al formato oficial de unidades: nombre y resultado dentro
 * de cada tabla, tomados de la ficha. Solo actúa si no hay sílabos ni procesos
 * abiertos; en otro estado no cambia nada y deja el aviso en el registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        $template = SyllabusTemplate::query()->first();
        if ($template === null) {
            return;
        }
        try {
            $result = app(ProtectTemplateResponses::class)->execute($template);
            echo '  unidades desde la ficha: '.json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (ValidationException $exception) {
            echo '  unidades desde la ficha omitido: '.collect($exception->errors())->flatten()->first().PHP_EOL;
        }
    }

    public function down(): void {}
};
