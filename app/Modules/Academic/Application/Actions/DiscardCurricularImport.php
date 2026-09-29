<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\Queries\CurricularImportsViewData;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularImport;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DiscardCurricularImport
{
    public function __construct(
        private readonly CurricularImportsViewData $view,
        private readonly ReleaseCurricularImportDocuments $release,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(string $id, User $actor, Request $request): void
    {
        $role = $this->view->role($request);
        $import = DB::transaction(function () use ($actor, $id, $request, $role): CurricularImport {
            $import = CurricularImport::query()->where('carrera_id', $role->carrera_id)->lockForUpdate()->findOrFail($id);
            if (! in_array($import->estado_importacion, ['lista', 'fallida'], true)) {
                throw new HttpResponseException(response()->json([
                    'code' => 'importacion_curricular_no_descartable',
                    'message' => 'Solo se descarta una importación lista para revisar o que no se pudo procesar.',
                ], 409));
            }
            $import->delete();
            $this->audit->execute(
                actorId: $actor->id, roleAssignmentId: $role->id,
                action: 'academico.importacion_curricular.descartada', resourceType: 'importacion_curricular', resourceId: $import->id,
                result: 'exito', metadata: ['career_id' => $import->carrera_id, 'documents' => count($import->documentos_importacion)],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $import;
        });
        $this->release->execute($import);
    }
}
