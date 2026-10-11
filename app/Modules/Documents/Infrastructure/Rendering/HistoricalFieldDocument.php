<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\UnitsTable;
use App\Modules\Syllabus\Application\IdentificationCard;

final class HistoricalFieldDocument
{
    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    public static function of(array $field): array
    {
        if (is_array($field['document'] ?? null)) {
            return $field['document'];
        }
        $value = $field['value'] ?? null;
        if (FreeText::isDocument($value)) {
            return $value;
        }
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        if (($field['type'] ?? null) === 'fijo' && is_array($config['content'] ?? null)) {
            return $config['content'];
        }
        if (($field['type'] ?? null) === 'automatico' && ($config['source'] ?? null) === 'identification_card' && is_array($value)) {
            return IdentificationCard::document($value);
        }
        if (($field['type'] ?? null) === 'tabla') {
            return FreeText::fixedTable($config['columns'] ?? [], $config['rows'] ?? [], is_array($value) ? $value : []);
        }
        if (($field['type'] ?? null) === 'unidades') {
            $units = is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
            $units = $units === [] ? [['nombre' => '', 'resultado' => '', 'semanas' => array_fill(0, 16, [])]] : $units;
            $week = 0;
            $content = [];
            foreach ($units as $index => $unit) {
                $content[] = (new UnitsTable)->table($unit, $index + 1, $week);
                $content[] = ['type' => 'paragraph'];
            }

            return ['type' => 'doc', 'content' => $content];
        }

        return $value === null ? FieldDocument::empty() : FreeText::fromText(WordValues::display($value));
    }
}
