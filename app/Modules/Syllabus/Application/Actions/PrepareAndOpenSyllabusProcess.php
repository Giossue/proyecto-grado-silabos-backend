<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Atajo administrativo para el primer ciclo de una convocatoria institucional.
 *
 * La preparación y la apertura son una sola operación para que un fallo de las
 * precondiciones no deje un proceso a medio configurar. Las acciones individuales
 * siguen disponibles para corregir o administrar procesos existentes.
 */
class PrepareAndOpenSyllabusProcess
{
    public function __construct(
        private readonly CreateSyllabusProcess $create,
        private readonly TransitionSyllabusProcess $transition,
    ) {}

    /** @param array{period_id: string, starts_at: string, due_at: string} $data */
    public function execute(array $data, User $actor, Request $request): SyllabusProcess
    {
        return DB::transaction(function () use ($data, $actor, $request): SyllabusProcess {
            $process = $this->create->execute($data, $actor, $request);

            return $this->transition->execute(
                $process,
                TransitionSyllabusProcess::OPEN,
                null,
                $actor,
                $request,
            );
        });
    }
}
