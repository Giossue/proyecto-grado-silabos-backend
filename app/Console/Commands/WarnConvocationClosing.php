<?php

namespace App\Console\Commands;

use App\Modules\Syllabus\Application\NotifyProcessClosingSoon;
use Illuminate\Console\Command;

class WarnConvocationClosing extends Command
{
    protected $signature = 'convocatorias:avisar-cierre';

    protected $description = 'Avisa 3 días antes del último día de entrega a docentes y coordinación con sílabos sin aprobar';

    public function handle(NotifyProcessClosingSoon $warn): int
    {
        $warned = $warn->execute();
        $this->info("Convocatorias avisadas: {$warned}.");

        return self::SUCCESS;
    }
}
