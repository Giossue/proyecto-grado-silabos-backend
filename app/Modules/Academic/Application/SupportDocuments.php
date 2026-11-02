<?php

namespace App\Modules\Academic\Application;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Jobs\PrepareSupportDocumentViewJob;
use App\Modules\Academic\Infrastructure\Persistence\Models\SupportDocument;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class SupportDocuments
{
    public const DISK = 'private';

    public const MAX_KILOBYTES = 20480;

    /** @var list<string> */
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

    public function __construct(private readonly ActiveRole $roles, private readonly RecordAuditEvent $audit) {}

    public function coordinationCareer(Request $request): string
    {
        $role = $this->roles->resolve($request);
        abort_unless($role?->role->codigo_rol === RoleCode::Coordinator->value && $role->carrera_id !== null && $role->career?->activo === true, 403);

        return $role->carrera_id;
    }

    public function readableCareer(Request $request): string
    {
        $role = $this->roles->resolve($request);
        abort_unless($this->readsCareer($role), 403);

        return (string) $role?->carrera_id;
    }

    public function assertReadable(Request $request, SupportDocument $document): void
    {
        abort_unless($this->readableCareer($request) === $document->carrera_id, 404);
    }

    /** @return Collection<int, SupportDocument> */
    public function forCareer(string $careerId): Collection
    {
        return SupportDocument::query()->with('original')
            ->where('carrera_id', $careerId)
            ->orderByDesc('subido_en')->orderBy('titulo_documento')
            ->get();
    }

    public function store(Request $request, User $actor, UploadedFile $file, string $title): SupportDocument
    {
        $careerId = $this->coordinationCareer($request);
        $content = (string) $file->get();
        $extension = strtolower($file->getClientOriginalExtension());
        $id = (string) Str::uuid();
        $path = "documentos-apoyo/{$careerId}/{$id}/original.{$extension}";
        $isPdf = $extension === 'pdf';
        Storage::disk(self::DISK)->put($path, $content);

        try {
            $document = $this->record($request, $actor, $file, $title, $careerId, $content, $id, $path, $isPdf);
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->deleteDirectory(dirname($path));
            throw $exception;
        }
        if (! $isPdf) {
            PrepareSupportDocumentViewJob::dispatch($id);
        }

        return $document->load('original');
    }

    private function record(Request $request, User $actor, UploadedFile $file, string $title, string $careerId, string $content, string $id, string $path, bool $isPdf): SupportDocument
    {
        return DB::transaction(function () use ($request, $actor, $file, $title, $careerId, $content, $id, $path, $isPdf): SupportDocument {
            $object = StoredObject::query()->create([
                'disco' => self::DISK,
                'ruta_interna' => $path,
                'nombre_logico' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime' => (string) ($file->getMimeType() ?? 'application/octet-stream'),
                'tamano_bytes' => strlen($content),
                'huella_sha256' => hash('sha256', $content),
                'clasificacion' => 'documento_apoyo',
                'propietario_usuario_id' => $actor->id,
            ]);
            $document = SupportDocument::query()->create([
                'documento_apoyo_id' => $id,
                'carrera_id' => $careerId,
                'titulo_documento' => $title,
                'archivo_id' => $object->id,
                'archivo_vista_id' => $isPdf ? $object->id : null,
                'estado_vista' => $isPdf ? SupportDocument::VIEW_READY : SupportDocument::VIEW_PENDING,
                'subido_por' => $actor->id,
                'subido_en' => now(),
            ]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'academico.documento_apoyo.subida',
                resourceType: 'documento_apoyo',
                resourceId: $id,
                result: 'exito',
                metadata: ['carrera_id' => $careerId, 'archivo_id' => $object->id, 'tamano_bytes' => strlen($content)],
            );

            return $document;
        });
    }

    public function delete(Request $request, User $actor, SupportDocument $document): void
    {
        abort_unless($this->coordinationCareer($request) === $document->carrera_id, 404);

        DB::transaction(function () use ($request, $actor, $document): void {
            $locked = SupportDocument::query()->lockForUpdate()->findOrFail($document->documento_apoyo_id);
            $objectIds = array_values(array_unique(array_filter([$locked->archivo_id, $locked->archivo_vista_id])));
            $objects = DB::table('archivos')->whereIn('archivo_id', $objectIds)->get(['disco', 'ruta_archivo']);
            $locked->delete();
            DB::table('archivos')->whereIn('archivo_id', $objectIds)->delete();
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'academico.documento_apoyo.eliminacion',
                resourceType: 'documento_apoyo',
                resourceId: $locked->documento_apoyo_id,
                result: 'exito',
                metadata: ['carrera_id' => $locked->carrera_id],
            );
            DB::afterCommit(function () use ($objects): void {
                foreach ($objects as $object) {
                    Storage::disk($object->disco)->delete($object->ruta_archivo);
                    Storage::disk($object->disco)->deleteDirectory(dirname($object->ruta_archivo));
                }
            });
        });
    }

    private function readsCareer(?RoleAssignment $role): bool
    {
        return $role !== null
            && in_array($role->role->codigo_rol, [RoleCode::Coordinator->value, RoleCode::Teacher->value], true)
            && $role->carrera_id !== null
            && $role->career?->activo === true;
    }
}
