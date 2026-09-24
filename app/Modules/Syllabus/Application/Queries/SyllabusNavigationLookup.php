<?php

namespace App\Modules\Syllabus\Application\Queries;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CurrentSyllabusTeacher;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;

/**
 * Consulta pública de solo lectura para que otros módulos (avisos) sepan a qué ámbito
 * pertenece un sílabo sin tocar los modelos de este módulo. Devuelve identificadores.
 */
class SyllabusNavigationLookup
{
    public function exists(string $syllabusId): bool
    {
        return Syllabus::query()->whereKey($syllabusId)->exists();
    }

    public function careerId(string $syllabusId): ?string
    {
        $careerId = Syllabus::query()->find($syllabusId)?->convocation()->value('carrera_id');

        return is_string($careerId) ? $careerId : null;
    }

    public function latestRevisionId(string $syllabusId): ?string
    {
        $revision = Syllabus::query()->find($syllabusId)?->revisions()->latest('numero_revision')->value('id');

        return is_string($revision) ? $revision : null;
    }

    /**
     * Asignaciones de rol con las que la persona es docente vigente del sílabo.
     *
     * @return array<int, mixed>
     */
    public function teacherAssignmentIds(string $syllabusId, string $userId): array
    {
        return CurrentSyllabusTeacher::query()
            ->where('silabo_id', $syllabusId)
            ->where('usuario_id', $userId)
            ->pluck('asignacion_rol_id')
            ->all();
    }

    /** @return list<string> */
    public function convocationCareerIds(string $convocationId): array
    {
        return $this->strings(Convocation::query()->whereKey($convocationId)->pluck('carrera_id')->all());
    }

    /** @return list<string> */
    public function processCareerIds(string $processId): array
    {
        return $this->strings(Convocation::query()->where('proceso_id', $processId)->pluck('carrera_id')->all());
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter($values, 'is_string'));
    }
}
