<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteManagedUser
{
    private const TRACES = [
        'asignaciones_paralelo' => 'asignacion_rol_id',
        'revisiones_silabo' => 'enviado_por',
        'exportaciones_silabo' => 'solicitado_por',
        'archivos' => 'subido_por',
        'firmas_documento' => 'usuario_id',
    ];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(User $target, User $actor, Request $request): void
    {
        $activeRole = $this->roles->resolve($request);

        DB::transaction(function () use ($activeRole, $actor, $target): void {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            if ($locked->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'user' => 'La cuenta ya fue activada por su titular. Desactívela en lugar de eliminarla.',
                ]);
            }
            foreach (self::TRACES as $table => $column) {
                $query = DB::table($table);
                if ($table === 'asignaciones_paralelo') {
                    $query->whereIn($column, DB::table('asignaciones_rol')->where('usuario_id', $locked->id)->select('asignacion_rol_id'));
                } else {
                    $query->where($column, $locked->id);
                }
                if ($query->exists()) {
                    throw ValidationException::withMessages([
                        'user' => 'La cuenta ya tiene actividad registrada. Desactívela en lugar de eliminarla.',
                    ]);
                }
            }

            $email = $locked->correo_electronico;
            $name = $locked->nombre;
            foreach (['notificaciones' => 'usuario_id', 'sesiones' => 'user_id', 'asignaciones_rol' => 'usuario_id'] as $table => $column) {
                DB::table($table)->where($column, $locked->id)->delete();
            }
            $locked->delete();

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'usuario.eliminado',
                resourceType: 'usuario',
                resourceId: $target->id,
                result: 'exito',
                metadata: ['email' => $email, 'name' => $name],
            );
        });
    }
}
