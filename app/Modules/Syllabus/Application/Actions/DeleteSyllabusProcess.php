<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteSyllabusProcess
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function isDeletable(SyllabusProcess $process): bool
    {
        return ! $this->hasTeacherActivity($process->id);
    }

    public function execute(SyllabusProcess $process, User $actor, Request $request): void
    {
        $activeRole = $this->roles->resolve($request);
        abort_unless($activeRole?->role->codigo_rol === RoleCode::Administrator->value, 403);

        try {
            DB::transaction(function () use ($activeRole, $actor, $process, $request): void {
                $locked = SyllabusProcess::query()->lockForUpdate()->findOrFail($process->id);
                $convocations = Convocation::query()
                    ->where('proceso_id', $locked->id)
                    ->lockForUpdate()
                    ->get();
                $syllabi = Syllabus::query()
                    ->whereIn('convocatoria_id', $convocations->modelKeys())
                    ->lockForUpdate()
                    ->get();

                if ($this->hasTeacherActivity($locked->id)) {
                    throw ValidationException::withMessages([
                        'process' => 'No se puede eliminar porque al menos un docente ya inició un sílabo. El historial académico debe conservarse.',
                    ]);
                }

                $periodId = $locked->periodo_academico_id;
                $state = $locked->estado;
                $convocationCount = $convocations->count();
                $syllabusCount = $syllabi->count();

                $syllabi->each->delete();
                $convocations->each->delete();
                $locked->delete();

                $this->audit->execute(
                    actorId: $actor->id,
                    roleAssignmentId: $activeRole->id,
                    action: 'proceso_silabos.eliminado',
                    resourceType: 'proceso_silabos',
                    resourceId: $process->id,
                    result: 'exito',
                    metadata: [
                        'period_id' => $periodId,
                        'previous_state' => $state,
                        'deleted_convocations' => $convocationCount,
                        'deleted_unstarted_syllabi' => $syllabusCount,
                    ],
                    correlationId: $request->attributes->getString('correlation_id') ?: null,
                );
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23503') {
                throw ValidationException::withMessages([
                    'process' => 'No se puede eliminar porque contiene actividad o historial relacionado.',
                ]);
            }

            throw $exception;
        }
    }

    private function hasTeacherActivity(string $processId): bool
    {
        $syllabi = Syllabus::query()
            ->whereHas('convocation', fn ($query) => $query->where('proceso_id', $processId));

        $hasCurrentActivity = (clone $syllabi)
            ->where(fn ($query) => $query
                ->where('estado', '!=', 'sin_iniciar')
                ->orWhereNotNull('iniciado_en')
                ->orWhereNotNull('guardado_en'))
            ->exists();

        if ($hasCurrentActivity) {
            return true;
        }

        return AuditEvent::query()
            ->where('accion', 'silabo.borrador_iniciado')
            ->whereIn('recurso_id', (clone $syllabi)->select('silabos.id'))
            ->exists();
    }
}
