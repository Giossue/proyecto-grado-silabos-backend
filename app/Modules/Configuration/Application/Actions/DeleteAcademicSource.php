<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteAcademicSource
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
    ) {}

    public function execute(AcademicSource $source, User $actor, Request $request): void
    {
        $activeRole = $this->roles->resolve($request);
        abort_unless(
            $activeRole?->role->codigo_rol === RoleCode::Coordinator->value
                && $activeRole->carrera_id === $source->carrera_id,
            403,
        );
        $this->locks->assertCareerEditable($source->carrera_id);

        try {
            DB::transaction(function () use ($activeRole, $actor, $request, $source): void {
                $locked = AcademicSource::query()->lockForUpdate()->findOrFail($source->id);

                $this->audit->execute(
                    actorId: $actor->id,
                    roleAssignmentId: $activeRole->id,
                    action: 'fuente.eliminada',
                    resourceType: 'fuente_academica',
                    resourceId: $locked->id,
                    result: 'exito',
                    correlationId: $request->attributes->getString('correlation_id') ?: null,
                );
                $locked->delete();
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23503') {
                throw ValidationException::withMessages([
                    'source' => 'La fuente ya forma parte de una convocatoria o evidencia y no puede eliminarse.',
                ]);
            }
            throw $exception;
        }
    }
}
