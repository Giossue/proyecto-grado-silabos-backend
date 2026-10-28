<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\Documents\Application\SignatureWorkflow;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;

/**
 * Lo que espera a cada coordinador: sílabos por revisar y por firmar de las convocatorias en
 * marcha de su carrera. Solo cuenta lo que puede atender; con la convocatoria cerrada no insiste.
 */
final class CoordinatorDailySummary
{
    public function __construct(
        private readonly SignatureWorkflow $signatures,
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    /** @return array<string, array{career: string, review: int, sign: int}> por carrera, solo con algo pendiente */
    public function pendingByCareer(): array
    {
        $pending = [];
        $convocations = Convocation::query()
            ->with('career:id,nombre')
            ->whereIn('estado', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED])
            ->whereHas('process', fn ($process) => $process->whereIn('estado', [SyllabusProcess::STATE_OPEN, SyllabusProcess::STATE_PAUSED]))
            ->get();
        foreach ($convocations as $convocation) {
            $review = 0;
            $sign = 0;
            $syllabi = Syllabus::query()->where('convocatoria_id', $convocation->id)->whereIn('estado', ['en_revision', 'aprobado'])->get();
            foreach ($syllabi as $syllabus) {
                if ($syllabus->estado === 'en_revision') {
                    $review += ReviewStage::of($syllabus) === ReviewStage::COORDINATION ? 1 : 0;
                } else {
                    $sign += $this->awaitsCoordinatorSignature($syllabus) ? 1 : 0;
                }
            }
            if ($review + $sign > 0) {
                $pending[$convocation->carrera_id] = ['career' => $convocation->career->nombre, 'review' => $review, 'sign' => $sign];
            }
        }

        return $pending;
    }

    /** @return array<string, list<array{career: string, review: int, sign: int}>> por coordinador */
    public function pendingByCoordinator(): array
    {
        $byUser = [];
        foreach ($this->pendingByCareer() as $careerId => $counts) {
            foreach ($this->recipients->coordinatorsForCareer((string) $careerId) as $userId) {
                $byUser[$userId][] = $counts;
            }
        }

        return $byUser;
    }

    /**
     * @param  list<string>  $userIds
     * @return list<User>
     */
    public function usersOf(array $userIds): array
    {
        return array_values(User::query()->whereIn('id', $userIds)->where('activo', true)->get()
            ->filter(fn (User $user): bool => $user->hasVerifiedEmail())->all());
    }

    private function awaitsCoordinatorSignature(Syllabus $syllabus): bool
    {
        $revision = SyllabusRevision::query()->where('silabo_id', $syllabus->id)->whereHas('approval')->orderByDesc('numero_revision')->first();
        $artifact = $revision instanceof SyllabusRevision ? $this->signatures->artifactFor($revision) : null;
        if ($artifact === null) {
            return false;
        }

        return $this->signatures->nextRole($this->signatures->signatures($artifact)) === DocumentSignature::ROLE_COORDINATION
            && ! $this->signatures->voided($revision);
    }
}
