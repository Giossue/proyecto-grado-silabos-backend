<?php

namespace App\Modules\Academic\Application;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class CurricularWorkbookReader
{
    /** @return array<string, mixed> */
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('El archivo no es un libro Excel legible.');
        }
        try {
            $total = 0;
            if ($zip->numFiles > 3000) {
                $this->invalid('El libro supera la cantidad de componentes permitida.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    $this->invalid('No se pudo comprobar el archivo Excel.');
                }
                $total += $stat['size'];
                if ($total > 80000000 || $stat['size'] > 20000000 || str_contains(strtolower($stat['name']), 'vbaproject')) {
                    $this->invalid('El libro contiene macros o supera el tamaño expandido permitido.');
                }
            }
            $shared = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $xpath = $this->xml($zip, 'xl/sharedStrings.xml');
                foreach ($xpath->query('//*[local-name()="si"]') ?: [] as $item) {
                    if (! $item instanceof DOMElement) {
                        continue;
                    }
                    $text = '';
                    foreach ($xpath->query('.//*[local-name()="t"]', $item) ?: [] as $piece) {
                        if ($piece instanceof DOMElement) {
                            $text .= $piece->textContent;
                        }
                    }
                    $shared[] = $text;
                }
            }
            $relationships = [];
            $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            foreach ($rels->query('//*[local-name()="Relationship"]') ?: [] as $rel) {
                if (! $rel instanceof DOMElement || $rel->getAttribute('TargetMode') === 'External') {
                    continue;
                }
                $target = $rel->getAttribute('Target');
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                if (! str_contains($target, '..') && preg_match('~^xl/worksheets/[^/]+\.xml$~', $target)) {
                    $relationships[$rel->getAttribute('Id')] = $target;
                }
            }
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $content = [];
            $cells = 0;
            $characters = 0;
            foreach ($workbook->query('//*[local-name()="sheet"]') ?: [] as $sheet) {
                if (! $sheet instanceof DOMElement) {
                    continue;
                }
                $name = $sheet->getAttribute('name');
                $id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                if (! isset($relationships[$id])) {
                    $this->invalid('El libro contiene una hoja que no se pudo resolver.');
                }
                $xpath = $this->xml($zip, $relationships[$id]);
                $content[] = ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Hoja: '.$name]]];
                $merges = [];
                foreach ($xpath->query('//*[local-name()="mergeCell"]') ?: [] as $merge) {
                    if ($merge instanceof DOMElement) {
                        $range = $merge->getAttribute('ref');
                        if (preg_match('/^[A-Z]+[1-9][0-9]*:[A-Z]+[1-9][0-9]*$/', $range)) {
                            $merges[] = $range;
                        }
                    }
                }
                if ($merges !== []) {
                    $content[] = $this->paragraph('Hoja «'.$name.'» — Rangos combinados (el valor de la primera celda abarca todo el rango): '.implode(', ', $merges));
                }
                foreach ($xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
                    if (! $row instanceof DOMElement) {
                        continue;
                    }
                    $values = [];
                    foreach ($xpath->query('./*[local-name()="c"]', $row) ?: [] as $cell) {
                        if (! $cell instanceof DOMElement) {
                            continue;
                        }
                        $reference = $cell->getAttribute('r');
                        if (! preg_match('/^[A-Z]{1,3}[1-9][0-9]{0,6}$/', $reference)) {
                            $this->invalid('El libro contiene una referencia de celda inválida.');
                        }
                        $raw = $xpath->evaluate('string(./*[local-name()="v"])', $cell);
                        $value = is_string($raw) ? $raw : '';
                        if ($cell->getAttribute('t') === 's') {
                            if (! ctype_digit($value) || ! array_key_exists((int) $value, $shared)) {
                                $this->invalid('Una celda referencia un texto inexistente.');
                            }
                            $value = $shared[(int) $value];
                        } elseif ($cell->getAttribute('t') === 'inlineStr') {
                            $value = '';
                            foreach ($xpath->query('./*[local-name()="is"]//*[local-name()="t"]', $cell) ?: [] as $piece) {
                                if ($piece instanceof DOMElement) {
                                    $value .= $piece->textContent;
                                }
                            }
                        }
                        $formulas = $xpath->query('./*[local-name()="f"]', $cell);
                        if ($formulas !== false && $formulas->length > 0) {
                            $value = $value === '' ? '[fórmula sin resultado guardado]' : $value.' [resultado guardado de fórmula]';
                        }
                        if (trim($value) !== '') {
                            $characters += mb_strlen($value);
                            if (++$cells > 15000 || $characters > 1000000) {
                                $this->invalid('El libro supera el máximo de contenido permitido.');
                            }
                            $values[] = $reference.': '.trim($value);
                        }
                    }
                    if ($values !== []) {
                        $content[] = $this->paragraph('Hoja «'.$name.'» — '.implode("\n", $values));
                    }
                }
            }
            if ($cells === 0) {
                $this->invalid('El libro no contiene celdas utilizables.');
            }

            return ['schemaVersion' => 1, 'type' => 'doc', 'content' => $content];
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $entry): DOMXPath
    {
        $xml = $zip->getFromName($entry);
        if (! is_string($xml) || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            $this->invalid('El libro contiene XML no permitido o incompleto.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                $this->invalid('No se pudo interpretar una hoja del libro.');
            }

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string, mixed> */
    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['files' => $message]);
    }
}
