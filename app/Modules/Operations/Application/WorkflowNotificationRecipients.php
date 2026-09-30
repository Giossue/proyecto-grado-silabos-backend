<?php

namespace App\Modules\Operations\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\CoordinatorAssignment;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CurrentSyllabusTeacher;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Database\Eloquent\Builder;

class WorkflowNotificationRecipients
{
    /** @return list<string> */
    public function coordinatorsFor(Syllabus $syllabus): array
    {
        $careerId = $syllabus->convocation()->value('carrera_id');

        return is_string($careerId) ? $this->coordinatorsForCareer($careerId) : [];
    }

    /** @return list<string> */
    public function coordinatorsForProcess(SyllabusProcess $process): array
    {
        $careerIds = Convocation::query()->where('proceso_id', $process->id)->pluck('carrera_id');
        if ($careerIds->isEmpty()) {
            $careerIds = Career::query()->where('activo', true)->pluck('id');
        }

        $ids = CoordinatorAssignment::query()
            ->whereIn('carrera_id', $careerIds)
            ->effective()
            ->whereHas('user', fn ($query) => $query->where('activo', true))
            ->pluck('usuario_id')
            ->all();

        return $this->uniqueStrings($ids);
    }

    /** @return list<string> */
    public function teachersWithPendingWork(SyllabusProcess $process): array
    {
        $convocationIds = $this->uniqueStrings(
            Convocation::query()->where('proceso_id', $process->id)->pluck('id')->all(),
        );

        return array_map('strval', array_keys($this->teacherSyllabusCounts($convocationIds, exceptStates: ['aprobado'])));
    }

    /** @return list<string> */
    public function coordinatorsForCareer(string $careerId): array
    {
        $ids = CoordinatorAssignment::query()
            ->where('carrera_id', $careerId)
            ->effective()
            ->whereHas('user', fn ($query) => $query->where('activo', true))
            ->pluck('usuario_id')
            ->all();

        return $this->uniqueStrings($ids);
    }

    /** @return list<string> */
    public function librarians(): array
    {
        $ids = RoleAssignment::query()
            ->effective()
            ->where('rol', RoleCode::Librarian->value)
            ->whereHas('user', fn ($query) => $query->where('activo', true))
            ->pluck('usuario_id')
            ->all();

        return $this->uniqueStrings($ids);
    }

    /** @return list<string> */
    public function teachersFor(Syllabus $syllabus): array
    {
        $ids = $this->activeCollaborators()
            ->where('silabo_id', $syllabus->id)
            ->pluck('usuario_id')
            ->all();

        return $this->uniqueStrings($ids);
    }

    /**
     * @param  list<string>  $convocationIds
     * @param  list<string>  $exceptStates
     * @return array<string, int>
     */
    public function teacherSyllabusCounts(array $convocationIds, array $exceptStates = []): array
    {
        $rows = $this->activeCollaborators()
            ->whereHas('syllabus', fn ($query) => $query
                ->whereIn('convocatoria_id', $convocationIds)
                ->when($exceptStates !== [], fn ($query) => $query->whereNotIn('estado', $exceptStates)))
            ->get(['usuario_id', 'silabo_id']);

        $counts = [];
        foreach ($rows->unique(fn ($row): string => $row->usuario_id.'|'.$row->silabo_id) as $row) {
            $counts[$row->usuario_id] = ($counts[$row->usuario_id] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return Builder<CurrentSyllabusTeacher> */
    private function activeCollaborators(): Builder
    {
        return CurrentSyllabusTeacher::query()->withActiveUser();
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private function uniqueStrings(iterable $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return array_values(array_unique($strings));
    }
}
