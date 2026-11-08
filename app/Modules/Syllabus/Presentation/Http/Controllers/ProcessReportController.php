<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Syllabus\Application\Queries\ProcessReport;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Spreadsheets\ProcessReportWorkbook;
use App\Modules\Syllabus\Presentation\Http\Requests\ViewProcessReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcessReportController extends Controller
{
    /** Reporte de la convocatoria elegida; sin elegir, la más reciente. */
    public function show(ViewProcessReportRequest $request, ProcessReport $report): JsonResponse
    {
        $process = $this->process($request);

        return response()->json([
            'options' => $report->options(),
            'report' => $process instanceof SyllabusProcess ? $report->build($process) : null,
        ]);
    }

    public function export(ViewProcessReportRequest $request, ProcessReport $report, ProcessReportWorkbook $workbook): StreamedResponse
    {
        $process = $this->process($request);
        abort_unless($process instanceof SyllabusProcess, 404, 'No hay convocatorias para reportar.');
        $data = $report->build($process);
        $book = $workbook->build($data);

        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->setPreCalculateFormulas(false)->save('php://output');
        }, 'reporte-'.Str::slug((string) ($data['process']['period'] ?? 'convocatoria')).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function process(ViewProcessReportRequest $request): ?SyllabusProcess
    {
        $id = $request->string('process')->toString();
        if ($id !== '') {
            return SyllabusProcess::query()->findOrFail($id);
        }

        return SyllabusProcess::query()->orderByDesc('inicia_en')->first();
    }
}
