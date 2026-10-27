<?php

use App\Modules\Configuration\Application\Actions\ProtectTemplateResponses;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Validation\ValidationException;

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
            echo '  unidades escritas por el docente: '.json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (ValidationException $exception) {
            echo '  unidades escritas por el docente omitido: '.collect($exception->errors())->flatten()->first().PHP_EOL;
        }
    }

    public function down(): void {}
};
