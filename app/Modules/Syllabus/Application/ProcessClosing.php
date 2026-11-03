<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;

final class ProcessClosing
{
    public function __construct(private readonly SyllabusSnapshot $snapshots) {}

    public function close(SyllabusProcess $locked): void
    {
        $locked->update(['estado' => SyllabusProcess::STATE_CLOSED]);
        $syllabi = Syllabus::query()->whereHas('convocation', fn ($query) => $query->where('proceso_id', $locked->id))
            ->whereNotIn('estado', ['en_revision', 'aprobado'])->lockForUpdate()->get();
        foreach ($syllabi as $syllabus) {
            $snapshot = $this->snapshots->build($syllabus);
            $syllabus->update(['contexto_academico' => [...$syllabus->contexto_academico, 'closed_document' => $snapshot]]);
        }
        Convocation::query()
            ->where('proceso_id', $locked->id)
            ->whereIn('estado', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED])
            ->update(['estado' => Convocation::STATE_CLOSED]);
    }
}
