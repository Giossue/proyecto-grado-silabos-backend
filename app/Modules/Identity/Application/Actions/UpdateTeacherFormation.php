<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La formación y experiencia del docente se escribe una vez en su perfil. Los sílabos que aún
 * puede editar la reciben al guardar; los enviados conservan lo que enviaron.
 */
class UpdateTeacherFormation
{
    /** @var list<string> */
    public const SYNCED_STATES = ['sin_iniciar', 'borrador', 'correccion_solicitada'];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(User $user, string $formation, Request $request): User
    {
        return DB::transaction(function () use ($formation, $request, $user): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->formacion_experiencia = trim($formation);
            if (! $locked->isDirty()) {
                return $locked;
            }
            $locked->save();

            $field = FieldDefinition::query()->where('origen_dato_definicion_campo', FieldDefinition::ORIGIN_TEACHER)->first();
            if ($field !== null) {
                $syllabusIds = Syllabus::query()
                    ->whereCurrentTeacher($locked)
                    ->whereIn('estado', self::SYNCED_STATES)
                    ->pluck('id');
                foreach ($syllabusIds as $syllabusId) {
                    FieldValue::query()->updateOrCreate(
                        ['silabo_id' => $syllabusId, 'definicion_campo_id' => $field->id],
                        ['valor' => $locked->formacion_experiencia],
                    );
                }
            }

            $this->audit->execute(
                actorId: $locked->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'usuario.formacion_actualizada',
                resourceType: 'usuario',
                resourceId: $locked->id,
                result: 'exito',
            );

            return $locked;
        });
    }
}
