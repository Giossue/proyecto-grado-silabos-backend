<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\ResponseKind;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartDraft
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly SubjectProfileFields $profiles,
    ) {}

    public function execute(string $syllabusId, User $actor, Request $request): Syllabus
    {
        return DB::transaction(function () use ($actor, $request, $syllabusId): Syllabus {
            $syllabus = Syllabus::query()->lockForUpdate()->findOrFail($syllabusId);
            if ($syllabus->estado !== 'sin_iniciar') {
                throw ValidationException::withMessages(['syllabus' => 'El borrador ya fue iniciado o no está editable.']);
            }
            $source = self::previousPeriodSource($syllabus, $actor);
            if ($source !== null) {
                $this->inherit($syllabus, $source);
            }
            $syllabus->update(['estado' => 'borrador', 'iniciado_en' => now(), 'guardado_en' => now(), 'version_bloqueo' => 1]);
            $activeRole = $this->roles->resolve($request);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'silabo.borrador_iniciado',
                resourceType: 'silabo',
                resourceId: $syllabus->id,
                result: 'exito',
                metadata: $source === null ? [] : ['inherited_from_syllabus_id' => $source->id],
            );

            return $syllabus;
        });
    }

    public static function previousPeriodSource(Syllabus $syllabus, User $teacher): ?Syllabus
    {
        $subjectId = $syllabus->parallel()->value('asignatura_id');
        $source = Syllabus::query()
            ->whereKeyNot($syllabus->getKey())
            ->whereNotNull('iniciado_en')
            ->whereHas('convocation', fn (Builder $convocation): Builder => $convocation->where('estado', Convocation::STATE_CLOSED))
            ->whereHas('parallel', fn (Builder $parallel): Builder => $parallel->where('asignatura_id', $subjectId))
            ->writtenBy($teacher)
            ->orderByDesc('iniciado_en')
            ->first();
        $modifiedAt = SyllabusTemplate::institutional()->plantilla_modificada_en;
        if ($source === null || ($modifiedAt !== null && $modifiedAt->greaterThan($source->iniciado_en))) {
            return null;
        }

        return $source;
    }

    private function inherit(Syllabus $syllabus, Syllabus $source): void
    {
        $setups = $this->profiles->templates((string) $syllabus->parallel()->value('asignatura_id'));
        $fields = FieldDefinition::query()
            ->where('plantilla_id', SyllabusTemplate::institutional()->id)
            ->where('quien_llena', '!=', FieldDefinition::INSTITUTIONAL)
            ->get()
            ->filter(fn (FieldDefinition $field): bool => SubjectProfileFields::teacherEditable($field, $setups))
            ->keyBy('id');
        $previous = FieldValue::query()
            ->where('silabo_id', $source->id)
            ->whereIn('definicion_campo_id', $fields->keys())
            ->get();
        foreach ($previous as $value) {
            $setup = $setups[$value->definicion_campo_id] ?? null;
            FieldValue::query()->updateOrCreate(
                ['silabo_id' => $syllabus->id, 'definicion_campo_id' => $value->definicion_campo_id],
                ['valor' => $setup === null && ! ResponseKind::within($value->valor)
                    ? FieldResponse::normalize($fields->get($value->definicion_campo_id)->contenido, $value->valor)
                    : FieldResponse::carried($setup ?? $fields->get($value->definicion_campo_id)->contenido, $value->valor)],
            );
        }
    }
}
