<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Validation\ValidationException;

final class GenerateConvocationSyllabi
{
    public function __construct(
        private readonly AcademicContextSnapshot $academicContext,
        private readonly InheritMasterValues $inherit,
    ) {}

    /** @param list<string>|null $subjectIds */
    public function execute(Convocation $convocation, ?string $subjectId = null, ?array $subjectIds = null, bool $allowEmpty = false, bool $skipIncomplete = false): int
    {
        $parallels = Parallel::query()
            ->whereHas('subject', fn ($query) => $query->where('carrera_id', $convocation->carrera_id)->where('activo', true)->where('genera_silabo', true))
            ->when($subjectId !== null, fn ($query) => $query->where('asignatura_id', $subjectId))
            ->when($subjectIds !== null, fn ($query) => $query->whereIn('asignatura_id', $subjectIds))
            ->with([
                'subject.career.faculty',
                'teacherAssignments' => fn ($assignmentQuery) => $assignmentQuery
                    ->where('asignaciones_paralelo.asignacion_paralelo_activa', true)
                    ->whereHas('roleAssignment', fn ($roleQuery) => $roleQuery
                        ->where('asignaciones_rol.asignacion_rol_activa', true)
                        ->where('asignaciones_rol.rol', RoleCode::Teacher->value)
                        ->where('asignaciones_rol.carrera_id', $convocation->carrera_id)
                        ->whereHas('user', fn ($userQuery) => $userQuery
                            ->where('usuarios.usuario_activo', true)
                            ->whereNotNull('usuarios.correo_verificado_en')))
                    ->with('roleAssignment')
                    ->lockForUpdate(),
            ])
            ->orderBy('asignatura_id')
            ->orderBy('codigo')
            ->lockForUpdate()
            ->get();

        if ($parallels->isEmpty() && $allowEmpty) {
            return 0;
        }
        if ($parallels->isEmpty()) {
            throw ValidationException::withMessages([
                'convocation' => 'No existen paralelos de la carrera.',
            ]);
        }

        $covered = Syllabus::query()
            ->where('convocatoria_id', $convocation->id)
            ->pluck('paralelo_id')
            ->flip();

        foreach ($parallels->groupBy('asignatura_id') as $subjectParallels) {
            $pending = $subjectParallels->reject(fn (Parallel $parallel): bool => $covered->has($parallel->id));
            if (! $skipIncomplete && $pending->contains(fn (Parallel $parallel): bool => $parallel->teacherAssignments->count() !== 1)) {
                throw ValidationException::withMessages([
                    'convocation' => "La materia {$subjectParallels->first()->subject->nombre} debe tener exactamente un docente vigente por paralelo.",
                ]);
            }
        }

        $generated = 0;
        foreach ($parallels as $parallel) {
            if ($covered->has($parallel->id) || ($skipIncomplete && $parallel->teacherAssignments->count() !== 1)) {
                continue;
            }
            $this->generate($convocation, $parallel);
            $generated++;
        }

        return $generated;
    }

    public function generateForParallel(Convocation $convocation, Parallel $parallel): void
    {
        $parallel->loadMissing('subject.career.faculty');
        $this->generate($convocation, $parallel);
    }

    private function generate(Convocation $convocation, Parallel $parallel): void
    {
        $convocation->loadMissing('process.academicPeriod');
        $syllabus = Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'paralelo_id' => $parallel->id,
            'contexto_academico' => $this->academicContext->build($parallel, $convocation->process->academicPeriod),
            'estado' => 'sin_iniciar',
        ]);

        $fields = SyllabusTemplate::institutional()->sections
            ->flatMap(fn ($section) => $section->blocks->flatMap(fn ($block) => $block->fields));
        $this->inherit->execute($syllabus, $fields, $parallel);
    }
}
