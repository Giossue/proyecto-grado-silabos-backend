<?php

namespace App\Modules\Syllabus\Domain\Policies;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Auth\Access\Response;

class SyllabusPolicy
{
    public function __construct(private readonly ActiveRole $roles) {}

    public function viewAny(User $user): bool
    {
        return $user->activo && $this->roles->hasRole(request(), RoleCode::Teacher);
    }

    public function reviewAny(User $user): bool
    {
        return $user->activo
            && $this->roles->hasRole(request(), RoleCode::Coordinator);
    }

    public function view(User $user, Syllabus $syllabus): bool
    {
        $activeRole = $this->roles->resolve(request());
        if (! $user->activo || $activeRole === null) {
            return false;
        }
        if ($this->isReviewer($activeRole, $syllabus)) {
            return true;
        }
        if ($activeRole->role->codigo_rol !== RoleCode::Teacher->value
            || $activeRole->carrera_id !== $syllabus->convocation()->value('carrera_id')) {
            return false;
        }

        return $this->hasCurrentCollaboration($user, $syllabus)
            || Syllabus::query()->whereKey($syllabus->getKey())->writtenBy($user)->exists();
    }

    public function start(User $user, Syllabus $syllabus): bool
    {
        return $this->canTeacherEdit($user, $syllabus) && $syllabus->estado === 'sin_iniciar';
    }

    public function edit(User $user, Syllabus $syllabus): bool
    {
        return $this->canTeacherEdit($user, $syllabus)
            && in_array($syllabus->estado, ['borrador', 'correccion_solicitada'], true);
    }

    public function submit(User $user, Syllabus $syllabus): bool
    {
        return $this->canTeacherEdit($user, $syllabus, enforceDeadline: false)
            && in_array($syllabus->estado, ['borrador', 'correccion_solicitada', 'en_revision'], true);
    }

    public function reset(User $user, Syllabus $syllabus): Response
    {
        if ($syllabus->estado !== 'aprobado' || ! $this->roles->hasRole(request(), RoleCode::Coordinator)) {
            return Response::deny();
        }
        if (! $this->review($user, $syllabus)) {
            return Response::deny();
        }
        // Reiniciar es una decisión más: en pausa tampoco se reinicia (2026-11-02).
        $block = self::decisionBlock($syllabus);
        if ($block !== null) {
            return Response::deny($block);
        }

        return Response::allow();
    }

    public function decide(User $user, Syllabus $syllabus): Response
    {
        if (! $this->review($user, $syllabus)) {
            return Response::deny();
        }
        $block = self::decisionBlock($syllabus);

        return $block === null ? Response::allow() : Response::deny($block);
    }

    private const CLOSED = 'La convocatoria está cerrada: la revisión queda solo para consulta y no admite decisiones.';

    /**
     * Por qué Coordinación o Biblioteca no pueden decidir ahora (aprobar, firmar, pedir corrección, devolver):
     * convocatoria cerrada, o en pausa (de la carrera o institucional), igual que el docente (2026-11-02).
     */
    public static function decisionBlock(Syllabus $syllabus): ?string
    {
        return self::blockOf($syllabus->convocation()->with('process:id,estado')->first());
    }

    /**
     * Igual que decisionBlock, pero dentro de la transacción de la decisión: bloquea en modo compartido
     * el proceso y luego la convocatoria (el mismo orden que las pausas y cierres) para que nadie los
     * pause o cierre entre esta comprobación y el fin de la decisión.
     */
    public static function lockedDecisionBlock(Syllabus $syllabus): ?string
    {
        $processId = Convocation::query()->find($syllabus->convocatoria_id)?->proceso_id;
        if ($processId === null) {
            return self::CLOSED;
        }
        SyllabusProcess::query()->whereKey($processId)->sharedLock()->first();
        $convocation = Convocation::query()->whereKey($syllabus->convocatoria_id)->sharedLock()->first();
        $convocation?->load('process:id,estado');

        return self::blockOf($convocation);
    }

    private static function blockOf(?Convocation $convocation): ?string
    {
        if ($convocation === null || ! self::convocationAllowsDecisions($convocation->estado)) {
            return self::CLOSED;
        }
        if ($convocation->estado === Convocation::STATE_PAUSED) {
            return 'La convocatoria de la carrera está en pausa: las revisiones se retoman cuando se reanude.';
        }
        if ($convocation->process->estado === SyllabusProcess::STATE_PAUSED) {
            return 'Administración pausó la convocatoria institucional: las revisiones se retoman cuando se reanude.';
        }

        return null;
    }

    public static function convocationAllowsDecisions(mixed $state): bool
    {
        return in_array($state, [Convocation::STATE_OPEN, Convocation::STATE_PAUSED], true);
    }

    public function review(User $user, Syllabus $syllabus): bool
    {
        $activeRole = $this->roles->resolve(request());

        return $user->activo && $activeRole !== null && $this->isReviewer($activeRole, $syllabus);
    }

    private function isReviewer(RoleAssignment $activeRole, Syllabus $syllabus): bool
    {
        $careerId = $syllabus->convocation()->value('carrera_id');

        return match ($activeRole->role->codigo_rol) {
            RoleCode::Coordinator->value => $activeRole->carrera_id === $careerId,
            RoleCode::Librarian->value => LibrarySubmission::query()
                ->whereHas('revision', fn ($revision) => $revision->where('silabo_id', $syllabus->id))
                ->exists(),
            default => false,
        };
    }

    private function canTeacherEdit(User $user, Syllabus $syllabus, bool $enforceDeadline = true): bool
    {
        if (! $this->isCurrentTeacher($user, $syllabus)) {
            return false;
        }
        $convocation = $syllabus->convocation()->running()->first();

        return $convocation !== null && (! $enforceDeadline || ! $convocation->deadlinePassed());
    }

    private function isCurrentTeacher(User $user, Syllabus $syllabus): bool
    {
        $activeRole = $this->roles->resolve(request());

        return $user->activo
            && $activeRole?->role->codigo_rol === RoleCode::Teacher->value
            && $activeRole->carrera_id === $syllabus->convocation()->value('carrera_id')
            && $this->hasCurrentCollaboration($user, $syllabus);
    }

    private function hasCurrentCollaboration(User $user, Syllabus $syllabus): bool
    {
        return Syllabus::query()->whereKey($syllabus->getKey())->whereCurrentTeacher($user)->exists();
    }
}
