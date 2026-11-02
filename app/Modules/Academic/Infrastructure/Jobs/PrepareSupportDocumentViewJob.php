<?php

namespace App\Modules\Academic\Infrastructure\Jobs;

use App\Modules\Academic\Infrastructure\Persistence\Models\SupportDocument;
use App\Modules\Academic\Infrastructure\Spreadsheets\SheetsFitPageWidth;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Documents\Infrastructure\Rendering\GotenbergPdfConverter;
use App\Support\Queue\RunsOnce;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[UniqueFor(900)]
class PrepareSupportDocumentViewJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnce, SerializesModels;

    public int $tries = 3;

    public int $timeout = 150;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $documentId)
    {
        $this->onQueue('documentos');
    }

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    public function handle(GotenbergPdfConverter $converter, SheetsFitPageWidth $sheets): void
    {
        $document = SupportDocument::query()->find($this->documentId);
        if ($document === null || $document->estado_vista !== SupportDocument::VIEW_PENDING) {
            return;
        }
        if (! $converter->enabled()) {
            $document->update(['estado_vista' => SupportDocument::VIEW_NONE]);

            return;
        }

        $original = $document->original;
        $disk = Storage::disk($original->disco);
        $content = (string) $disk->get($original->ruta_interna);
        $extension = strtolower(pathinfo($original->ruta_interna, PATHINFO_EXTENSION));
        $filename = "documento.{$extension}";
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            try {
                $content = $sheets->apply($content, $extension);
                $filename = 'documento.xlsx';
            } catch (Throwable $exception) {
                Log::warning('Documento de apoyo convertido sin ajustar sus hojas.', ['documento_apoyo_id' => $document->documento_apoyo_id, 'error' => $exception->getMessage()]);
            }
        }
        $pdf = $converter->convertFile($content, $filename);
        $path = dirname($original->ruta_interna).'/vista.pdf';
        $disk->put($path, $pdf);

        DB::transaction(function () use ($document, $original, $path, $pdf): void {
            $locked = SupportDocument::query()->lockForUpdate()->find($document->documento_apoyo_id);
            $preview = StoredObject::query()->firstOrCreate(['disco' => $original->disco, 'ruta_interna' => $path], [
                'nombre_logico' => pathinfo($original->nombre_logico, PATHINFO_FILENAME).'.pdf',
                'mime' => 'application/pdf',
                'tamano_bytes' => strlen($pdf),
                'huella_sha256' => hash('sha256', $pdf),
                'clasificacion' => 'documento_apoyo_vista',
                'propietario_usuario_id' => $original->propietario_usuario_id,
            ]);
            if ($locked !== null && $locked->estado_vista === SupportDocument::VIEW_PENDING) {
                $locked->update(['archivo_vista_id' => $preview->id, 'estado_vista' => SupportDocument::VIEW_READY]);
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        SupportDocument::query()->whereKey($this->documentId)
            ->where('estado_vista', SupportDocument::VIEW_PENDING)
            ->update(['estado_vista' => SupportDocument::VIEW_NONE]);
        Log::warning('No se pudo preparar la vista del documento de apoyo.', ['documento_apoyo_id' => $this->documentId, 'error' => $exception->getMessage()]);
    }
}
