<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;

final class CoordinatorDailySummary
{
    public function __construct(
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    /** @return array<string, array{career: string, review: int}> */
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
            $syllabi = Syllabus::query()->where('convocatoria_id', $convocation->id)->where('estado', 'en_revision')->get();
            foreach ($syllabi as $syllabus) {
                $review += ReviewStage::of($syllabus) === ReviewStage::COORDINATION ? 1 : 0;
            }
            if ($review > 0) {
                $pending[$convocation->carrera_id] = ['career' => $convocation->career->nombre, 'review' => $review];
            }
        }

        return $pending;
    }

    /** @return array<string, list<array{career: string, review: int}>> */
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
}
