<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\FichaWorkbookContext;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;
use App\Modules\Academic\Infrastructure\Spreadsheets\FichaWorkbook;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PreviewFichaWorkbook
{
    public function __construct(private readonly ActiveRole $roles, private readonly ProcessLocks $locks, private readonly FichaWorkbookContext $context, private readonly FichaWorkbook $workbook, private readonly ForgetFichaImport $imports, private readonly RecordAuditEvent $audit) {}

    public function execute(UploadedFile $file, User $actor, Request $request): FichaImport
    {
        $role = $this->roles->resolve($request);
        abort_unless($actor->activo && AcademicStructurePermissions::isCareerContext($role) && $role?->carrera_id !== null, 403);
        $careerId = $role->carrera_id;
        $this->locks->assertCareerEditable($careerId);
        $started = now();
        $definition = $this->context->for($careerId);
        $subjects = $this->workbook->read($file->getRealPath(), $definition);

        return DB::transaction(function () use ($careerId, $actor, $role, $file, $definition, $subjects, $started): FichaImport {
            DB::table('carreras')->where('carrera_id', $careerId)->lockForUpdate()->first();
            $this->locks->assertCareerEditable($careerId);
            if ($this->context->for($careerId)->fingerprint() !== $definition->fingerprint()) {
                throw ValidationException::withMessages(['file' => 'La plantilla o las materias cambiaron durante la lectura. Descargue un Excel nuevo.']);
            }
            FichaImport::query()->where('carrera_id', $careerId)->where('propuesta_importacion->format', FichaWorkbook::FORMAT)->where('estado_importacion', FichaImport::READY)
                ->get()->each(fn (FichaImport $old) => $this->imports->execute($old));
            $import = FichaImport::query()->create([
                'carrera_id' => $careerId, 'solicitado_por' => $actor->id, 'estado_importacion' => FichaImport::READY,
                'documentos_importacion' => [['name' => Str::limit($file->getClientOriginalName(), 120, ''), 'path' => '', 'size' => (int) $file->getSize()]],
                'solicitado_en' => $started,
                'propuesta_importacion' => [
                    'format' => FichaWorkbook::FORMAT, 'fingerprint' => $definition->fingerprint(), 'subjects' => $subjects,
                ],
            ]);
            $this->audit->execute($actor->id, $role->id, 'academico.ficha_importacion_solicitada', 'carrera', $careerId, 'exito', ['documents' => 1, 'method' => FichaWorkbook::FORMAT]);

            return $import;
        });
    }
}
