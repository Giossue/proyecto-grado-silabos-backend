<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\FichaWorkbookContext;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Spreadsheets\FichaWorkbook;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadFichaWorkbook
{
    public function __construct(private readonly ActiveRole $roles, private readonly ProcessLocks $locks, private readonly FichaWorkbookContext $context, private readonly FichaWorkbook $workbook) {}

    public function execute(User $actor, Request $request): StreamedResponse
    {
        $role = $this->roles->resolve($request);
        abort_unless($actor->activo && AcademicStructurePermissions::isCareerContext($role) && $role?->carrera_id !== null, 403);
        $this->locks->assertCareerEditable($role->carrera_id);
        $book = $this->workbook->create($this->context->for($role->carrera_id));

        return response()->streamDownload(function () use ($book): void {
            try {
                (new Xlsx($book))->setPreCalculateFormulas(false)->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'proyecto-carrera.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }
}
