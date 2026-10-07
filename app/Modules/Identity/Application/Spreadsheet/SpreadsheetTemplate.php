<?php

namespace App\Modules\Identity\Application\Spreadsheet;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpreadsheetTemplate
{
    /**
     * @param  list<string>  $headers
     * @param  list<string>  $example
     */
    public function download(string $filename, array $headers, array $example): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $example): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($headers));
            $writer->addRow(Row::fromValues($example));
            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
