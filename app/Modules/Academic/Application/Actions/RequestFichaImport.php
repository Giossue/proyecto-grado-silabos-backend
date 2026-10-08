<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\Contracts\FichaExtractionGateway;
use App\Modules\Academic\Infrastructure\Jobs\PrepareFichaImportJob;
use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RequestFichaImport
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly ProcessLocks $locks,
        private readonly FichaExtractionGateway $gateway,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param list<UploadedFile> $files */
    public function execute(array $files, User $actor, Request $request): FichaImport
    {
        $role = $this->roles->resolve($request);
        abort_unless($actor->activo && AcademicStructurePermissions::isCareerContext($role) && $role?->carrera_id !== null, 403);
        $careerId = $role->carrera_id;
        $this->locks->assertCareerEditable($careerId);
        if (! $this->gateway->available()) {
            throw ValidationException::withMessages(['files' => 'El asistente de IA no está configurado en este entorno.']);
        }

        $import = DB::transaction(function () use ($careerId, $actor, $files): FichaImport {
            DB::table('carreras')->where('carrera_id', $careerId)->lockForUpdate()->first();
            $running = FichaImport::query()->where('carrera_id', $careerId)
                ->whereIn('estado_importacion', [FichaImport::PENDING, FichaImport::RUNNING])->exists();
            if ($running) {
                throw ValidationException::withMessages(['files' => 'Ya hay una importación en curso. Espere a que termine.']);
            }
            // Una propuesta anterior sin aplicar se reemplaza.
            FichaImport::query()->where('carrera_id', $careerId)->whereIn('estado_importacion', [FichaImport::READY, FichaImport::FAILED])
                ->get()->each(fn (FichaImport $old) => $this->forget($old));

            $import = new FichaImport(['carrera_id' => $careerId, 'solicitado_por' => $actor->id, 'estado_importacion' => FichaImport::PENDING, 'documentos_importacion' => [], 'solicitado_en' => now()]);
            $import->save();
            $documents = [];
            foreach (array_values($files) as $index => $file) {
                $detected = strtolower((string) $file->extension());
                $extension = in_array($detected, ['pdf', 'xlsx', 'xls'], true) ? $detected : strtolower($file->getClientOriginalExtension());
                $path = "importaciones-ficha/{$import->id}/".($index + 1).'-'.Str::random(8).'.'.$extension;
                Storage::disk('private')->put($path, (string) file_get_contents($file->getRealPath()));
                $documents[] = ['name' => Str::limit($file->getClientOriginalName(), 120, ''), 'path' => $path, 'size' => (int) $file->getSize()];
            }
            $import->update(['documentos_importacion' => $documents]);

            return $import;
        });

        $this->audit->execute($actor->id, $role->id, 'academico.ficha_importacion_solicitada', 'carrera', $careerId, 'exito', ['documents' => count($files)]);
        PrepareFichaImportJob::dispatch($import->id)->afterCommit();

        return $import->fresh();
    }

    public function forget(FichaImport $import): void
    {
        Storage::disk('private')->deleteDirectory("importaciones-ficha/{$import->id}");
        $import->update(['estado_importacion' => FichaImport::DISCARDED, 'documentos_importacion' => []]);
    }
}
