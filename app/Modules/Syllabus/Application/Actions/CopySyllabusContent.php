<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Domain\Exceptions\DraftConflictException;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CopySyllabusContent
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @return Collection<int, Syllabus> */
    public static function sources(Syllabus $syllabus, User $teacher): Collection
    {
        if (! in_array($syllabus->estado, ['sin_iniciar', 'borrador'], true)) {
            return new Collection;
        }

        $subjectId = $syllabus->parallel()->value('asignatura_id');

        return Syllabus::query()
            ->with('parallel:id,codigo')
            ->where('convocatoria_id', $syllabus->convocatoria_id)
            ->whereKeyNot($syllabus->getKey())
            ->where('estado', '!=', 'sin_iniciar')
            ->whereHas('parallel', fn ($parallel) => $parallel->where('asignatura_id', $subjectId))
            ->whereCurrentTeacher($teacher)
            ->get()
            ->sortBy(fn (Syllabus $source): string => $source->academicParallelCode())
            ->values();
    }

    public function execute(Syllabus $syllabus, Syllabus $source, int $lockVersion, User $actor, Request $request): Syllabus
    {
        return DB::transaction(function () use ($actor, $lockVersion, $request, $source, $syllabus): Syllabus {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            if (! self::sources($locked, $actor)->contains(fn (Syllabus $candidate): bool => $candidate->is($source))) {
                throw ValidationException::withMessages(['syllabus' => 'Solo puede copiar desde otro paralelo suyo de la misma asignatura, y solo hacia un sílabo que aún no ha enviado.']);
            }
            if ($locked->version_bloqueo !== $lockVersion) {
                throw new DraftConflictException($locked->version_bloqueo);
            }

            $fieldIds = FieldDefinition::query()
                ->where('plantilla_id', SyllabusTemplate::institutional()->id)
                ->where('editable_docente', true)
                ->where('tipo', '!=', 'calculo')
                ->pluck('id');
            $sourceValues = FieldValue::query()
                ->where('silabo_id', $source->id)
                ->whereIn('definicion_campo_id', $fieldIds)
                ->get();

            FieldValue::query()
                ->where('silabo_id', $locked->id)
                ->whereIn('definicion_campo_id', $fieldIds)
                ->delete();
            foreach ($sourceValues as $value) {
                FieldValue::query()->create([
                    'silabo_id' => $locked->id,
                    'definicion_campo_id' => $value->definicion_campo_id,
                    'valor' => $value->valor,
                ]);
            }

            $locked->update([
                'estado' => 'borrador',
                'iniciado_en' => $locked->iniciado_en ?? now(),
                'guardado_en' => now(),
                'version_bloqueo' => $locked->version_bloqueo + 1,
            ]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'silabo.contenido_copiado',
                resourceType: 'silabo',
                resourceId: $locked->id,
                result: 'exito',
                metadata: ['source_syllabus_id' => $source->id, 'lock_version' => $locked->version_bloqueo],
            );

            return $locked;
        });
    }
}
