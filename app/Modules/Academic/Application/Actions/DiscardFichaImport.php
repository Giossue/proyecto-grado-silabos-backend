<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Http\Request;

final class DiscardFichaImport
{
    public function __construct(private readonly ActiveRole $roles, private readonly ForgetFichaImport $forget) {}

    public function execute(FichaImport $import, User $actor, Request $request): void
    {
        $role = $this->roles->resolve($request);
        abort_unless($actor->activo && AcademicStructurePermissions::isCareerContext($role) && $role?->carrera_id === $import->carrera_id, 403);
        if ($import->inProgress() || $import->estado_importacion === FichaImport::RUNNING) {
            $this->forget->execute($import);
        }
    }
}
