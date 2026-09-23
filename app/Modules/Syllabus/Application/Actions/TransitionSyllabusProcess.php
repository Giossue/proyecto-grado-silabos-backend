<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo del proceso institucional: abierto ⇄ pausado → cerrado.
 *
 * Pausar detiene a toda la universidad —envíos y ediciones docentes— y es lo que
 * habilita cambiar la plantilla; por eso pide motivo. Reanudar vuelve a comprobar la
 * plantilla: pudo cambiarse durante la pausa. Cerrar es definitivo.
 */
class TransitionSyllabusProcess
{
    public const PAUSE = 'pausar';

    public const RESUME = 'reanudar';

    public const CLOSE = 'cerrar';

    /** @var array<string, array{from: list<string>, to: string}> */
    private const TRANSITIONS = [
        self::PAUSE => ['from' => [SyllabusProcess::STATE_OPEN], 'to' => SyllabusProcess::STATE_PAUSED],
        self::RESUME => ['from' => [SyllabusProcess::STATE_PAUSED], 'to' => SyllabusProcess::STATE_OPEN],
        self::CLOSE => ['from' => [SyllabusProcess::STATE_OPEN, SyllabusProcess::STATE_PAUSED], 'to' => SyllabusProcess::STATE_CLOSED],
    ];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly TemplateStructureValidator $templateStructure,
    ) {}

    public function execute(
        SyllabusProcess $process,
        string $transition,
        ?string $reason,
        User $actor,
        Request $request,
    ): SyllabusProcess {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Administrator->value) {
            abort(403);
        }
        $rule = self::TRANSITIONS[$transition] ?? null;
        if ($rule === null) {
            throw ValidationException::withMessages(['transition' => 'La acción sobre el proceso no existe.']);
        }

        return DB::transaction(function () use ($activeRole, $actor, $process, $reason, $request, $rule): SyllabusProcess {
            $locked = SyllabusProcess::query()->lockForUpdate()->with('template')->findOrFail($process->id);
            if (! in_array($locked->estado, $rule['from'], true)) {
                throw ValidationException::withMessages([
                    'process' => 'El proceso no está en un estado que admita esta acción.',
                ]);
            }

            if ($rule['to'] === SyllabusProcess::STATE_OPEN) {
                // Lo que antes garantizaba «publicar»: la plantilla se usa completa o no se usa.
                $this->templateStructure->assertUsable($locked->template, 'process');
            }

            $previous = $locked->estado;
            $locked->update([
                'estado' => $rule['to'],
            ]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: "proceso_silabos.{$rule['to']}",
                resourceType: 'proceso_silabos',
                resourceId: $locked->id,
                result: 'exito',
                metadata: array_filter([
                    'previous_state' => $previous,
                    'reason' => $reason,
                ], fn (mixed $value): bool => $value !== null),
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $locked;
        });
    }
}
