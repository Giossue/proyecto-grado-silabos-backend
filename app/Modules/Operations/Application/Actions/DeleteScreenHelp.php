<?php

namespace App\Modules\Operations\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\ScreenHelp;
use Illuminate\Support\Facades\DB;

class DeleteScreenHelp
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function execute(User $actor, ?RoleAssignment $activeRole, string $screen): void
    {
        DB::transaction(function () use ($actor, $activeRole, $screen): void {
            $help = ScreenHelp::query()->where('pantalla', $screen)->first();
            if ($help === null) {
                return;
            }
            $help->delete();

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'ayuda.eliminada',
                resourceType: 'ayuda_pantalla',
                resourceId: $help->id,
                result: 'exito',
                metadata: ['key' => $screen],
            );
        });
    }
}
