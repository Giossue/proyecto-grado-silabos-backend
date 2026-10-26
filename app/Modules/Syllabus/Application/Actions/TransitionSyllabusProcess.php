<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly RegenerateProcessSyllabi $regenerate,
        private readonly SyllabusSnapshot $snapshots,
    ) {}

    /** @return array{process: SyllabusProcess, confirmation_required: bool, counts: array<string, int>} */
    public function execute(
        SyllabusProcess $process,
        string $transition,
        User $actor,
        Request $request,
    ): array {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Administrator->value) {
            abort(403);
        }
        $rule = self::TRANSITIONS[$transition] ?? null;
        if ($rule === null) {
            throw ValidationException::withMessages(['transition' => 'La acción sobre el proceso no existe.']);
        }

        return DB::transaction(function () use ($activeRole, $actor, $process, $request, $rule): array {
            $locked = SyllabusProcess::query()->lockForUpdate()->findOrFail($process->id);
            if (! in_array($locked->estado, $rule['from'], true)) {
                throw ValidationException::withMessages([
                    'process' => 'El proceso no está en un estado que admita esta acción.',
                ]);
            }

            if ($rule['to'] === SyllabusProcess::STATE_OPEN) {
                $this->templateStructure->assertUsable(SyllabusTemplate::institutional(), 'process');
            }

            $templateChange = false;
            $counts = [];
            $template = SyllabusTemplate::query()->first();
            if ($rule['to'] === SyllabusProcess::STATE_OPEN
                && $locked->pausado_en !== null
                && $template?->plantilla_modificada_en instanceof CarbonInterface) {
                $templateChange = $template->plantilla_modificada_en->greaterThan($locked->pausado_en);
                if ($templateChange) {
                    $counts = $this->regenerate->counts($locked);
                    if (! $request->boolean('confirmar_rehacer')) {
                        return [
                            'process' => $locked,
                            'confirmation_required' => true,
                            'counts' => $counts,
                        ];
                    }
                }
            }

            $previous = $locked->estado;
            if ($templateChange) {
                $this->regenerate->regenerate($locked, $template->plantilla_modificada_en->toISOString());
                $metadata = ['total' => array_sum($counts)];
                foreach ($counts as $state => $count) {
                    $metadata['estado_'.$state] = $count;
                }
                $this->audit->execute(
                    actorId: $actor->id,
                    roleAssignmentId: $activeRole->id,
                    action: 'proceso.plantilla_rehecho',
                    resourceType: 'proceso_silabos',
                    resourceId: $locked->id,
                    result: 'exito',
                    metadata: $metadata,
                );
            }
            $locked->update([
                'estado' => $rule['to'],
            ]);
            if ($rule['to'] === SyllabusProcess::STATE_PAUSED) {
                DB::table('convocatorias_universidad')
                    ->where('convocatoria_universidad_id', $locked->id)
                    ->update(['pausado_en' => DB::raw('clock_timestamp()')]);
            }
            if ($rule['to'] === SyllabusProcess::STATE_CLOSED) {
                $syllabi = Syllabus::query()->whereHas('convocation', fn ($query) => $query->where('proceso_id', $locked->id))
                    ->whereNotIn('estado', ['en_revision', 'aprobado'])->lockForUpdate()->get();
                foreach ($syllabi as $syllabus) {
                    $snapshot = $this->snapshots->build($syllabus);
                    $syllabus->update(['contexto_academico' => [...$syllabus->contexto_academico, 'closed_document' => $snapshot]]);
                }
                Convocation::query()
                    ->where('proceso_id', $locked->id)
                    ->whereIn('estado', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED])
                    ->update(['estado' => Convocation::STATE_CLOSED]);
            }
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: "proceso_silabos.{$rule['to']}",
                resourceType: 'proceso_silabos',
                resourceId: $locked->id,
                result: 'exito',
                metadata: ['previous_state' => $previous],
            );

            return [
                'process' => $locked,
                'confirmation_required' => false,
                'counts' => $counts,
            ];
        });
    }
}
