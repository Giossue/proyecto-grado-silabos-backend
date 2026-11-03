<?php

namespace App\Console\Commands;

use App\Modules\Syllabus\Application\Actions\CloseEndedProcesses;
use Illuminate\Console\Command;

class CloseEndedConvocations extends Command
{
    protected $signature = 'convocatorias:cerrar-vencidas';

    protected $description = 'Finaliza la convocatoria abierta o pausada cuyo período ya terminó, con el mismo efecto que Finalizar';

    public function handle(CloseEndedProcesses $close): int
    {
        $closed = $close->execute();
        $this->info("Convocatorias finalizadas: {$closed}.");

        return self::SUCCESS;
    }
}
