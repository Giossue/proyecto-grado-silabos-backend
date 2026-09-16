<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ConvocationSchedule;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Coordinación inicia el alcance heredado de la convocatoria institucional. */
class CreateConvocation
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly OpenConvocation $open,
    ) {}

    /** @param array{process_id: string, source_ids: list<string>} $data */
    public function execute(array $data, User $actor, Request $request): Convocation
    {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Coordinator->value || $activeRole->carrera_id === null) {
            abort(403);
        }

        return DB::transaction(function () use ($activeRole, $actor, $data, $request): Convocation {
            $process = SyllabusProcess::query()->lockForUpdate()->with('academicPeriod')->findOrFail($data['process_id']);
            if ($process->estado !== SyllabusProcess::STATE_OPEN) {
                throw ValidationException::withMessages(['process_id' => 'La convocatoria institucional todavía no está abierta.']);
            }
            if (Convocation::query()->where('carrera_id', $activeRole->carrera_id)->where('proceso_id', $process->id)->exists()) {
                throw ValidationException::withMessages(['process_id' => 'Esta carrera ya tiene una convocatoria para el período institucional.']);
            }
            $sourceIds = array_values(array_unique($data['source_ids']));
            $sources = AcademicSource::query()->whereIn('id', $sourceIds)->get();
            if ($sources->count() !== count($sourceIds)
                || $sources->contains(fn (AcademicSource $source): bool => ! $source->activo || $source->carrera_id !== $activeRole->carrera_id)) {
                throw ValidationException::withMessages(['source_ids' => 'Seleccione fuentes activas pertenecientes a su carrera.']);
            }

            $convocation = Convocation::query()->create([
                'carrera_id' => $activeRole->carrera_id,
                'proceso_id' => $process->id,
                'estado' => Convocation::STATE_PREPARATION,
            ]);
            foreach ($sourceIds as $sourceId) {
                DB::table('fuentes_convocatoria')->insert([
                    'id' => (string) Str::uuid(), 'convocatoria_id' => $convocation->id,
                    'fuente_academica_id' => $sourceId,
                ]);
            }
            foreach ([ConvocationSchedule::STAGE_START => $process->inicia_en, ConvocationSchedule::STAGE_DRAFT => $process->entrega_en] as $stage => $dueAt) {
                DB::table('fechas_limite_convocatoria')->insert([
                    'id' => (string) Str::uuid(), 'convocatoria_id' => $convocation->id, 'etapa_fecha_limite_convocatoria' => $stage,
                    'vence_en' => $dueAt,
                ]);
            }
            $this->audit->execute(
                actorId: $actor->id, roleAssignmentId: $activeRole->id,
                action: 'convocatoria.iniciada', resourceType: 'convocatoria', resourceId: $convocation->id,
                result: 'exito',
                metadata: ['process_id' => $process->id, 'period_id' => $process->periodo_academico_id, 'source_count' => count($sourceIds)],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $this->open->execute($convocation->id, $actor, $request);
        });
    }
}
