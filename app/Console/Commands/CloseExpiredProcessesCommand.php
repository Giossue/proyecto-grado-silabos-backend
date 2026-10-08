<?php

namespace App\Console\Commands;

use App\Modules\Syllabus\Application\Actions\CloseExpiredProcesses;
use Illuminate\Console\Command;

class CloseExpiredProcessesCommand extends Command
{
    protected $signature = 'procesos:finalizar-vencidos';

    protected $description = 'Finaliza las convocatorias institucionales cuya fecha de entrega ya pasó';

    public function handle(CloseExpiredProcesses $action): int
    {
        $closed = $action->execute();
        $this->components->info("Convocatorias finalizadas: {$closed}.");

        return self::SUCCESS;
    }
}
