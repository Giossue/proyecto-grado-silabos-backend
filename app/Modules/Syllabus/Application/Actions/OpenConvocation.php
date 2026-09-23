<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenConvocation
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly TemplateStructureValidator $templateStructure,
        private readonly InstitutionalLogos $logos,
        private readonly GenerateConvocationSyllabi $generator,
    ) {}

    public function execute(string $convocationId, User $actor, Request $request): Convocation
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($actor, $convocationId, $activeRole, $request): Convocation {
            $convocation = Convocation::query()
                ->lockForUpdate()
                ->with(['career.faculty', 'sources', 'process.template'])
                ->findOrFail($convocationId);
            if ($activeRole?->carrera_id !== $convocation->carrera_id || $activeRole->role->codigo_rol !== 'coordinador') {
                abort(403);
            }
            if ($convocation->estado !== 'preparacion') {
                throw ValidationException::withMessages(['convocation' => 'Solo una convocatoria en preparación puede abrirse.']);
            }
            if (! $this->logos->facultyIsConfigured($convocation->career->faculty)) {
                throw ValidationException::withMessages([
                    'convocation' => 'Cargue el logo de la facultad desde Puesta en marcha del Panel antes de abrir la convocatoria.',
                ]);
            }
            // El calendario lo marca Administración: sin proceso abierto no hay a qué convocar.
            if ($convocation->process->estado !== SyllabusProcess::STATE_OPEN) {
                throw ValidationException::withMessages(['convocation' => match ($convocation->process->estado) {
                    SyllabusProcess::STATE_PAUSED => 'El proceso institucional está en pausa. Podrá abrir cuando Administración lo reanude.',
                    SyllabusProcess::STATE_CLOSED => 'El proceso institucional ya se cerró. Prepare la convocatoria sobre un proceso vigente.',
                    default => 'El proceso institucional todavía no se abre. Podrá abrir cuando Administración lo inicie.',
                }]);
            }
            $template = $convocation->process->template;
            $this->templateStructure->assertUsable($template, 'convocation');
            if ($convocation->sources->isEmpty()
                || $convocation->sources->contains(fn ($source): bool => ! $source->activo)) {
                throw ValidationException::withMessages(['convocation' => 'Las fuentes fijadas deben continuar activas al abrir.']);
            }

            $generated = $this->generator->execute($convocation);

            $convocation->update(['estado' => 'abierta']);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'convocatoria.abierta',
                resourceType: 'convocatoria',
                resourceId: $convocation->id,
                result: 'exito',
                metadata: ['generated_count' => $generated],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $convocation;
        });
    }

}
