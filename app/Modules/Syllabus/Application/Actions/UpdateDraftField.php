<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
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

    /** @param array{version_bloqueo: int, value?: mixed} $data */
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
            if (! $field->editable_docente || $field->tipo === 'calculo' || (! $field->hasLegacyClass() && ! $field->fieldType()->teacherEditable() && ! ($field->typeConfig()['adjustable'] ?? false))) {
                throw ValidationException::withMessages(['field' => 'Este campo lo llena el sistema; el docente no puede cambiarlo.']);
            }

            FieldValue::query()->updateOrCreate([
                'silabo_id' => $locked->id,
                'definicion_campo_id' => $field->id,
            ], ['valor' => $this->value($field, $data['value'] ?? null)]);

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

    private function value(FieldDefinition $field, mixed $value): mixed
    {
        if ($field->hasLegacyClass()) {
            return $field->tipo === 'texto_libre' && $value !== null ? FreeText::normalize($value) : $value;
        }

        return $field->fieldType()->validateValue($value, $field->typeConfig(), 'value');
    }
}
