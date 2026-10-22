<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteSyllabusProcess
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(SyllabusProcess $process, User $actor, Request $request): void
    {
        $activeRole = $this->roles->resolve($request);
        abort_unless($activeRole?->role->codigo_rol === RoleCode::Administrator->value, 403);

        DB::transaction(function () use ($activeRole, $actor, $process): void {
            $locked = SyllabusProcess::query()->lockForUpdate()->findOrFail($process->id);
            if ($locked->estado === SyllabusProcess::STATE_CLOSED) {
                throw ValidationException::withMessages([
                    'process' => 'Una convocatoria cerrada no se elimina: queda como historial.',
                ]);
            }
            $delivered = $locked->deliveredSyllabiCount();
            if ($delivered > 0) {
                throw ValidationException::withMessages([
                    'process' => "La convocatoria tiene {$delivered} sílabos ya enviados: no se puede eliminar. Ciérrala para conservarla como historial.",
                ]);
            }

            $convocations = Convocation::query()
                ->where('proceso_id', $locked->id)
                ->lockForUpdate()
                ->get();
            $syllabusIds = Syllabus::query()
                ->whereIn('convocatoria_id', $convocations->modelKeys())
                ->pluck('id')
                ->all();
            $state = $locked->estado;

            DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
            DB::table('notificaciones')->whereIn('recurso_id', $syllabusIds)->delete();
            DB::statement("SET LOCAL app.reinicio_institucional = 'off'");
            Syllabus::query()->whereKey($syllabusIds)->delete();
            Convocation::query()->whereKey($convocations->modelKeys())->delete();
            $locked->delete();

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'proceso_silabos.eliminado',
                resourceType: 'proceso_silabos',
                resourceId: $process->id,
                result: 'exito',
                metadata: [
                    'period_id' => $locked->periodo_academico_id,
                    'previous_state' => $state,
                    'deleted_convocations' => $convocations->count(),
                    'deleted_syllabi' => count($syllabusIds),
                ],
            );
        });
    }
}
