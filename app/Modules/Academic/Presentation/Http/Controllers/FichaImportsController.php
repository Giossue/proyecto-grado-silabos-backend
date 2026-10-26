<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\ApplyFichaImport;
use App\Modules\Academic\Application\Actions\DiscardFichaImport;
use App\Modules\Academic\Application\Actions\DownloadFichaWorkbook;
use App\Modules\Academic\Application\Actions\PreviewFichaWorkbook;
use App\Modules\Academic\Application\FichaFieldValues;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;
use App\Modules\Academic\Infrastructure\Spreadsheets\FichaWorkbook;
use App\Modules\Academic\Presentation\Http\Requests\ApplyFichaImportRequest;
use App\Modules\Academic\Presentation\Http\Requests\PreviewFichaWorkbookRequest;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FichaImportsController extends Controller
{
    public function __construct(private readonly FichaFieldValues $values) {}

    public function index(Request $request, ActiveRole $roles, SubjectProfileFields $profile): JsonResponse
    {
        $careerId = $this->careerId($request, $roles);
        $import = FichaImport::query()->where('carrera_id', $careerId)
            ->where('propuesta_importacion->format', FichaWorkbook::FORMAT)
            ->where('estado_importacion', FichaImport::READY)
            ->latest('solicitado_en')->first();

        return response()->json(['available' => true, 'import' => $import === null ? null : $this->detail($import, $profile)]);
    }

    public function template(Request $request, DownloadFichaWorkbook $action): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $action->execute($actor, $request);
    }

    public function spreadsheet(PreviewFichaWorkbookRequest $request, PreviewFichaWorkbook $action, SubjectProfileFields $profile): JsonResponse
    {
        $actor = $request->user();
        $file = $request->file('file');
        abort_unless($actor instanceof User && $file instanceof UploadedFile, 400);

        return response()->json(['import' => $this->detail($action->execute($file, $actor, $request), $profile)]);
    }

    public function apply(string $import, ApplyFichaImportRequest $request, ApplyFichaImport $action, SubjectProfileFields $profile): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = $action->execute(FichaImport::query()->findOrFail($import), array_values($request->validated('subjects')), $actor, $request, $request->validated('fields'));

        return response()->json(['message' => 'Fichas aplicadas.', 'import' => $this->detail($record, $profile)]);
    }

    public function destroy(string $import, Request $request, DiscardFichaImport $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute(FichaImport::query()->findOrFail($import), $actor, $request);

        return response()->json(['message' => 'Importación descartada.']);
    }

    private function careerId(Request $request, ActiveRole $roles): string
    {
        $role = $roles->resolve($request);
        abort_unless($request->user()?->activo === true && AcademicStructurePermissions::isCareerContext($role) && $role?->carrera_id !== null, 403);

        return $role->carrera_id;
    }

    /** @return array<string, mixed> */
    private function detail(FichaImport $import, SubjectProfileFields $profile): array
    {
        $fields = array_values($profile->fields()->map(fn ($field): array => $this->values->schema($field))->all());
        $subjects = $import->propuesta_importacion['subjects'] ?? [];
        $fieldIds = $profile->fields()->pluck('id', 'clave');
        $keysById = $fieldIds->flip();
        $current = [];
        FieldValue::query()->whereIn('asignatura_id', array_column($subjects, 'subject_id'))
            ->whereIn('definicion_campo_id', $fieldIds->values()->all())->get()
            ->each(function (FieldValue $value) use (&$current, $keysById): void {
                $text = FreeText::isDocument($value->valor) ? trim(FreeText::plainText(FreeText::normalize($value->valor))) : '';
                if ($text !== '') {
                    $current[$value->asignatura_id][(string) $keysById[$value->definicion_campo_id]] = $text;
                }
            });

        return [
            'id' => $import->id,
            'format' => $import->propuesta_importacion['format'] ?? null,
            'state' => $import->estado_importacion,
            'error' => $import->mensaje_error_importacion,
            'documents' => array_map(fn (array $document): array => ['name' => $document['name'], 'size' => $document['size']], $import->documentos_importacion),
            'fields' => $fields,
            'subjects' => array_map(fn (array $entry): array => [
                'subject_id' => $entry['subject_id'], 'code' => $entry['code'], 'name' => $entry['name'], 'cycle' => $entry['cycle'],
                'state' => $entry['state'], 'values' => (object) ($entry['values'] ?? []), 'replaces' => isset($current[$entry['subject_id']]),
                'current' => (object) ($current[$entry['subject_id']] ?? []),
                ...$this->values->coverage($entry, $fields),
            ], $subjects),
        ];
    }
}
