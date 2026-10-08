<?php

namespace App\Modules\Operations\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\ScreenHelp;
use Illuminate\Support\Facades\DB;

class SaveScreenHelp
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function execute(User $actor, ?RoleAssignment $activeRole, string $screen, string $title, string $content, bool $active): ScreenHelp
    {
        return DB::transaction(function () use ($actor, $activeRole, $screen, $title, $content, $active): ScreenHelp {
            $help = ScreenHelp::query()->updateOrCreate(['pantalla' => $screen], [
                'titulo_ayuda' => $title,
                'contenido_ayuda' => $content,
                'activa' => $active,
                'actualizado_por' => $actor->id,
                'actualizado_en' => now(),
            ]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'ayuda.guardada',
                resourceType: 'ayuda_pantalla',
                resourceId: $help->id,
                result: 'exito',
                metadata: ['key' => $screen, 'active' => $active],
            );

            return $help;
        });
    }
}
