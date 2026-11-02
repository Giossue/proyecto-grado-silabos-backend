<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\SupportDocuments;
use App\Modules\Academic\Infrastructure\Persistence\Models\SupportDocument;
use App\Modules\Academic\Presentation\Http\Requests\StoreSupportDocumentRequest;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportDocumentController extends Controller
{
    public function __construct(private readonly SupportDocuments $documents) {}

    public function coordinationIndex(Request $request): JsonResponse
    {
        return $this->list($this->documents->coordinationCareer($request));
    }

    public function teacherIndex(Request $request): JsonResponse
    {
        return $this->list($this->documents->readableCareer($request));
    }

    public function store(StoreSupportDocumentRequest $request): JsonResponse
    {
        $actor = $request->user();
        $file = $request->file('file');
        abort_unless($actor instanceof User && $file instanceof UploadedFile, 400);
        $document = $this->documents->store($request, $actor, $file, (string) $request->validated('title'));

        return response()->json(['message' => 'Documento subido.', 'document' => $this->item($document)], 201);
    }

    public function destroy(Request $request, SupportDocument $document): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $this->documents->delete($request, $actor, $document);

        return response()->json(['message' => 'Documento eliminado.']);
    }

    public function view(Request $request, SupportDocument $document): StreamedResponse
    {
        $this->documents->assertReadable($request, $document);
        $preview = $document->estado_vista === SupportDocument::VIEW_READY ? $document->preview : null;
        abort_unless($preview !== null && Storage::disk($preview->disco)->exists($preview->ruta_interna), 404);

        return Storage::disk($preview->disco)->response($preview->ruta_interna, $this->filename($document, 'pdf'), [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function download(Request $request, SupportDocument $document): StreamedResponse
    {
        $this->documents->assertReadable($request, $document);
        $original = $document->original;
        abort_unless(Storage::disk($original->disco)->exists($original->ruta_interna), 404);

        return Storage::disk($original->disco)->download($original->ruta_interna, $this->filename($document, $this->extension($original)), [
            'Content-Type' => $original->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function list(string $careerId): JsonResponse
    {
        return response()->json(['data' => $this->documents->forCareer($careerId)->map(fn (SupportDocument $document): array => $this->item($document))->values()]);
    }

    /** @return array<string, mixed> */
    private function item(SupportDocument $document): array
    {
        return [
            'id' => $document->documento_apoyo_id,
            'title' => $document->titulo_documento,
            'file_name' => $document->original->nombre_logico,
            'extension' => $this->extension($document->original),
            'size' => $document->original->tamano_bytes,
            'view' => $document->estado_vista,
            'uploaded_at' => $document->subido_en->toIso8601String(),
            'uploaded_by' => $document->uploader?->nombre,
        ];
    }

    private function extension(StoredObject $object): string
    {
        return strtolower(pathinfo($object->ruta_interna, PATHINFO_EXTENSION));
    }

    private function filename(SupportDocument $document, string $extension): string
    {
        return (Str::slug($document->titulo_documento) ?: 'documento').".{$extension}";
    }
}
