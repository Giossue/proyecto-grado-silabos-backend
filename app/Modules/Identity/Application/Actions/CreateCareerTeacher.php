<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\ActivationInvitation;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateCareerTeacher
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly CreateManagedUser $createUser,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array{nombre: string, correo_electronico: string}  $data
     * @return array{created: bool, attached: bool}
     */
    public function execute(array $data, User $actor, Request $request): array
    {
        Gate::forUser($actor)->authorize('createCareerTeacher', User::class);
        $activeRole = $this->roles->resolve($request);
        abort_unless($activeRole?->carrera_id !== null, 403);
        $careerId = $activeRole->carrera_id;
        $email = mb_strtolower(trim($data['correo_electronico']));

        return DB::transaction(function () use ($data, $actor, $request, $activeRole, $careerId, $email): array {
            $user = $this->findAccount($email);
            $created = false;

            if ($user === null) {
                try {
                    $user = $this->createUser->execute([
                        ...$data,
                        'correo_electronico' => $email,
                        'role_code' => RoleCode::Teacher->value,
                        'career_id' => $careerId,
                    ], $actor, $request);
                    $created = true;
                } catch (UniqueConstraintViolationException $exception) {
                    $user = $this->findAccount($email) ?? throw $exception;
                }
            }

            if (! $user->activo) {
                throw ValidationException::withMessages([
                    'correo_electronico' => 'No se puede incorporar esta cuenta. Solicite a Administración que revise su acceso.',
                ]);
            }

            if (RoleAssignment::query()
                ->where('usuario_id', $user->id)
                ->effective()
                ->where('rol', RoleCode::Administrator->value)
                ->exists()) {
                throw ValidationException::withMessages([
                    'correo_electronico' => 'No se puede incorporar como docente una cuenta con el rol Administrador.',
                ]);
            }

            $identity = [
                'usuario_id' => $user->id,
                'rol' => RoleCode::Teacher->value,
                'carrera_id' => $careerId,
            ];
            $assignment = RoleAssignment::query()->where($identity)->orderByDesc('activo')->first();

            // Un docente retirado de la carrera por Coordinación vuelve a incorporarse con la misma fila.
            $reactivated = $assignment !== null && ! $assignment->activo;
            if ($reactivated) {
                $assignment->update(['activo' => true]);
            }

            $assignment ??= RoleAssignment::query()->firstOrCreate([...$identity, 'activo' => true]);

            $attached = $created || $reactivated || $assignment->wasRecentlyCreated;

            if ($attached) {
                $this->audit->execute(
                    actorId: $actor->id,
                    roleAssignmentId: $activeRole->id,
                    action: 'usuario.docente_incorporado',
                    resourceType: 'usuario',
                    resourceId: $user->id,
                    result: 'exito',
                    metadata: ['carrera_id' => $careerId, 'cuenta_creada' => $created],
                );
            }

            if (! $user->hasVerifiedEmail() && ! ActivationInvitation::query()
                ->where('usuario_id', $user->id)
                ->whereNull('usada_en')
                ->whereNull('invalidada_en')
                ->where('expira_en', '>', now())
                ->exists()) {
                app(IssueActivationInvitation::class)->execute($user, $actor, $request, 'alta_coordinacion');
            }

            return ['created' => $created, 'attached' => $attached];
        });
    }

    private function findAccount(string $email): ?User
    {
        return User::query()->whereRaw('lower(correo_electronico) = ?', [$email])->lockForUpdate()->first();
    }
}
