<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\FichaWorkbookContext;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\FichaWorkbookPreview;
use App\Modules\Academic\Infrastructure\Spreadsheets\FichaWorkbook;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class PreviewFichaWorkbook
{
    public function __construct(private readonly ActiveRole $roles, private readonly ProcessLocks $locks, private readonly FichaWorkbookContext $context, private readonly FichaWorkbook $workbook) {}

    public function execute(UploadedFile $file, User $actor, Request $request): FichaWorkbookPreview
    {
        $role = $this->roles->resolve($request);
        abort_unless($actor->activo && AcademicStructurePermissions::isCareerContext($role) && $role?->carrera_id !== null, 403);
        $this->locks->assertCareerEditable($role->carrera_id);
        $definition = $this->context->for($role->carrera_id);
        $subjects = $this->workbook->read($file->getRealPath(), $definition);
        if ($this->context->for($role->carrera_id)->fingerprint() !== $definition->fingerprint()) {
            throw ValidationException::withMessages(['file' => 'La plantilla o las materias cambiaron durante la lectura. Descargue un Excel nuevo.']);
        }

        return new FichaWorkbookPreview($definition, hash('sha256', $file->getContent()), $subjects);
    }
}
