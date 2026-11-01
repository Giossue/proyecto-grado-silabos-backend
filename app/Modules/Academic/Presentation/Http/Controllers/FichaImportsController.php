<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\ApplyFichaImport;
use App\Modules\Academic\Application\Actions\DownloadFichaWorkbook;
use App\Modules\Academic\Application\Actions\PreviewFichaWorkbook;
use App\Modules\Academic\Application\FichaFieldValues;
use App\Modules\Academic\Domain\FichaWorkbookPreview;
use App\Modules\Academic\Infrastructure\Spreadsheets\FichaWorkbook;
use App\Modules\Academic\Presentation\Http\Requests\ApplyFichaImportRequest;
use App\Modules\Academic\Presentation\Http\Requests\PreviewFichaWorkbookRequest;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FichaImportsController extends Controller
{
    public function __construct(private readonly FichaFieldValues $values) {}

    public function template(Request $request, DownloadFichaWorkbook $action): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $action->execute($actor, $request);
    }

    public function spreadsheet(PreviewFichaWorkbookRequest $request, PreviewFichaWorkbook $action): JsonResponse
    {
        $actor = $request->user();
        $file = $request->file('file');
        abort_unless($actor instanceof User && $file instanceof UploadedFile, 400);

        return response()->json(['preview' => $this->detail($action->execute($file, $actor, $request))]);
    }

    public function apply(ApplyFichaImportRequest $request, ApplyFichaImport $action): JsonResponse
    {
        $actor = $request->user();
        $file = $request->file('file');
        abort_unless($actor instanceof User && $file instanceof UploadedFile, 400);
        $applied = $action->execute($file, $request->validated('fingerprint'), $request->validated('file_hash'), array_values($request->validated('subjects')), $actor, $request, $request->validated('fields'));

        return response()->json(['message' => 'Proyecto de carrera aplicado.', 'applied' => $applied]);
    }

    /** @return array<string, mixed> */
    private function detail(FichaWorkbookPreview $preview): array
    {
        $fields = array_map(fn (array $field): array => ['key' => $field['key'], 'label' => $field['label'], 'columns' => $field['columns']], $preview->definition->fields);
        $subjects = $preview->subjects;
        $fieldIds = collect($preview->definition->fields)->pluck('id', 'key');
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
            'format' => FichaWorkbook::FORMAT,
            'fingerprint' => $preview->definition->fingerprint(),
            'file_hash' => $preview->fileHash,
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
