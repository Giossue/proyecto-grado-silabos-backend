<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Domain\Exceptions\DraftConflictException;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDraftField
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array{version_bloqueo: int, value?: mixed}  $data
     */
    public function execute(Syllabus $syllabus, FieldDefinition $field, array $data, User $actor, Request $request): Syllabus
    {
        return DB::transaction(function () use ($actor, $data, $field, $request, $syllabus): Syllabus {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            if ($locked->version_bloqueo !== $data['version_bloqueo']) {
                throw new DraftConflictException($locked->version_bloqueo);
            }
            if (! in_array($locked->estado, ['borrador', 'correccion_solicitada'], true)) {
                throw ValidationException::withMessages(['syllabus' => 'El expediente no está en estado editable.']);
            }
            if ($field->plantilla_id !== SyllabusTemplate::institutional()->id || (! $field->getAttribute('vigente_en_plantilla') && ! $locked->values()->where('definicion_campo_id', $field->id)->exists())) {
                throw ValidationException::withMessages(['field' => 'El campo no pertenece a la plantilla vigente.']);
            }
            $current = FieldValue::query()->where('silabo_id', $locked->id)->where('definicion_campo_id', $field->id)->first();
            if (! $field->teacherEditable()) {
                throw ValidationException::withMessages(['field' => 'Este campo pertenece a Coordinación o a la institución y el docente no puede cambiarlo.']);
            }

            $document = FieldResponse::normalize($field->contenido, $data['value'] ?? FieldDocument::empty(), 'value');
            if (! DocumentBlock::sameStructure(FieldDocument::normalize($field->contenido), $document)) {
                throw ValidationException::withMessages(['value' => 'Los bloques de este campo los define la plantilla; el docente solo los responde.']);
            }

            FieldValue::query()->updateOrCreate([
                'silabo_id' => $locked->id,
                'definicion_campo_id' => $field->id,
            ], ['valor' => $document]);

            $locked->update([
                'version_bloqueo' => $locked->version_bloqueo + 1,
                'guardado_en' => now(),
            ]);
            $activeRole = $this->roles->resolve($request);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'silabo.campo_guardado',
                resourceType: 'silabo',
                resourceId: $locked->id,
                result: 'exito',
                metadata: ['field_key' => $field->clave, 'lock_version' => $locked->version_bloqueo],
            );

            return $locked;
        });
    }
}
