<?php

namespace App\Modules\Academic\Application\Actions;

use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularImport;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Documentos temporales (I-152): la lectura, el original y las imágenes de una
 * importación solo viven mientras la importación está abierta. Al guardar o descartar
 * se eliminan la lectura (`fuentes_academicas`) y los archivos; los datos confirmados
 * conservan su propio extracto y procedencia. `objetos_almacenados` es de solo agregar
 * por regla de la base: su ficha (nombre, tamaño y huella, sin contenido) permanece como
 * constancia. Un archivo que otra importación abierta todavía usa se conserva.
 */
final class ReleaseCurricularImportDocuments
{
    private const OPEN = ['pendiente', 'en_ejecucion', 'lista', 'fallida'];

    public function execute(CurricularImport $import): void
    {
        $paths = DB::transaction(function () use ($import): array {
            $inUse = CurricularImport::query()->where('carrera_id', $import->carrera_id)->whereKeyNot($import->id)
                ->whereIn('estado_importacion', self::OPEN)->get(['documentos_importacion'])
                ->flatMap(fn (CurricularImport $other): array => $other->documentos_importacion);
            $usedSources = $inUse->pluck('source_id')->filter()->all();
            $usedObjects = $inUse->pluck('original_object_id')->filter()->all();
            $paths = [];
            foreach ($import->documentos_importacion as $document) {
                $sourceId = $document['source_id'] ?? null;
                $source = is_string($sourceId) && ! in_array($sourceId, $usedSources, true)
                    ? AcademicSource::query()->where('carrera_id', $import->carrera_id)->lockForUpdate()->find($sourceId)
                    : null;
                $objectIds = [$document['original_object_id'] ?? null, $source?->objeto_pdf_id];
                if ($source !== null) {
                    // Imágenes extraídas por la lectura OCR de este documento.
                    $assets = StoredObject::query()->where('carrera_id', $import->carrera_id)
                        ->where('ruta_interna', 'like', "academic-sources/{$source->id}/%")->get();
                    foreach ($assets as $asset) {
                        $objectIds[] = $asset->id;
                    }
                    $source->delete();
                }
                foreach (array_unique(array_filter($objectIds)) as $objectId) {
                    if (in_array($objectId, $usedObjects, true)) {
                        continue;
                    }
                    $object = StoredObject::query()->where('carrera_id', $import->carrera_id)->find($objectId);
                    if ($object !== null && ! AcademicSource::query()->where('objeto_pdf_id', $object->id)->exists()) {
                        $paths[] = [$object->disco, $object->ruta_interna];
                    }
                }
            }

            return $paths;
        });
        // Los archivos se borran solo después de confirmar la base: un fallo no deja lecturas sin archivo.
        foreach ($paths as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
    }
}
