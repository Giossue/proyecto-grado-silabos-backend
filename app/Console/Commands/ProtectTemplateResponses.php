<?php

namespace App\Console\Commands;

use App\Modules\Configuration\Application\Actions\ProtectTemplateResponses as ProtectResponses;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Console\Command;

class ProtectTemplateResponses extends Command
{
    protected $signature = 'template:protect-responses {--apply}';

    protected $description = 'Activa respuestas protegidas y las unidades con datos del proyecto de carrera antes de crear sílabos';

    public function handle(ProtectResponses $action): int
    {
        if (! $this->option('apply')) {
            $this->info('Requiere respaldo verificado, convocatoria pausada o inexistente y ningún sílabo. Use --apply para activar sin borrar proyectos de carrera.');

            return self::SUCCESS;
        }
        $result = $action->execute(SyllabusTemplate::query()->firstOrFail());
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
