<?php

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Application\TemplateContext;
use Illuminate\Validation\ValidationException;

final class FieldResponse
{
    public static function enabled(): bool
    {
        return (TemplateContext::active()->toArray()['response_policy'] ?? null) === 'protected';
    }

    /** @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    public static function template(array $template): array
    {
        if (! self::hasSlots($template)) {
            $template['content'][] = ['type' => 'paragraph'];
        }

        return $template;
    }

    /** @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    public static function normalize(array $template, mixed $value, string $attribute = 'value'): array
    {
        $document = FieldDocument::normalize($value, $attribute);
        if (! self::enabled()) {
            return $document;
        }
        $template = self::template(FieldDocument::normalize($template));
        if (self::blank($document)) {
            return $template;
        }
        if (! self::matches($template, $document)) {
            throw ValidationException::withMessages([$attribute => 'Complete solo los espacios de respuesta. El texto, los encabezados y la estructura los define Administración.']);
        }

        return $document;
    }

    /** @param array<string, mixed> $template */
    public static function isEmpty(array $template, mixed $value): bool
    {
        if (! self::enabled()) {
            return FieldDocument::isEmpty($value);
        }
        if (! FreeText::isDocument($value)) {
            return true;
        }

        return ! self::answered(self::template($template), $value);
    }

    /** @param array<string, mixed> $node */
    public static function blank(array $node): bool
    {
        if (! in_array($node['type'] ?? '', ['paragraph', 'tableCell', 'doc'], true)) {
            return false;
        }
        foreach ($node['content'] ?? [] as $child) {
            if (($child['type'] ?? '') !== 'paragraph' || ! self::blank($child)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $table */
    public static function listTable(array $table): bool
    {
        $rows = $table['content'] ?? [];
        $last = array_pop($rows);

        return $rows !== [] && is_array($last) && ($last['content'] ?? []) !== []
            && array_all($rows, fn (array $row): bool => ($row['content'] ?? []) !== [] && array_all($row['content'], fn (array $cell): bool => ($cell['type'] ?? '') === 'tableHeader'))
            && array_all($last['content'], fn (array $cell): bool => self::blank($cell) && ($cell['attrs']['rowspan'] ?? 1) === 1);
    }

    /** @param array<string, mixed> $node */
    private static function hasSlots(array $node): bool
    {
        if (($node['type'] ?? '') === 'tableHeader') {
            return false;
        }

        return self::blank($node) || ($node['type'] ?? '') === 'documentBlock'
            || array_any($node['content'] ?? [], fn (array $child): bool => self::hasSlots($child));
    }

    /** @param array<string, mixed> $template
     * @param  array<string, mixed>  $value
     */
    private static function matches(array $template, array $value): bool
    {
        if (($template['type'] ?? '') !== ($value['type'] ?? '')) {
            return false;
        }
        if (($template['type'] ?? '') === 'tableHeader') {
            return $template === $value;
        }
        if (($template['type'] ?? '') === 'documentBlock') {
            return ($template['attrs']['kind'] ?? '') === ($value['attrs']['kind'] ?? '')
                && ($template['attrs']['props'] ?? []) === ($value['attrs']['props'] ?? []);
        }
        if (($template['attrs'] ?? []) !== ($value['attrs'] ?? [])) {
            return false;
        }
        if (self::blank($template)) {
            return self::answerOnly($value);
        }
        $expected = $template['content'] ?? [];
        $actual = $value['content'] ?? [];
        if (($template['type'] ?? '') === 'table' && self::listTable($template)) {
            $row = array_pop($expected);
            if (count($actual) < count($expected) + 1) {
                return false;
            }
            while (count($expected) < count($actual)) {
                $expected[] = $row;
            }
        }
        if (count($expected) !== count($actual)) {
            return false;
        }
        if ($expected === []) {
            return $template === $value;
        }
        foreach ($expected as $index => $child) {
            if (! self::matches($child, $actual[$index])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $node */
    private static function answerOnly(array $node): bool
    {
        return ! in_array($node['type'] ?? '', ['documentBlock', 'variable'], true)
            && array_all($node['content'] ?? [], fn (array $child): bool => self::answerOnly($child));
    }

    /** @param array<string, mixed> $template
     * @param  array<string, mixed>  $value
     */
    private static function answered(array $template, array $value): bool
    {
        if (self::blank($template) || ($template['type'] ?? '') === 'documentBlock') {
            return ! FieldDocument::isEmpty(['type' => 'doc', 'content' => [$value]]);
        }
        $rows = $template['content'] ?? [];
        foreach ($value['content'] ?? [] as $index => $child) {
            $source = $rows[$index] ?? (($template['type'] ?? '') === 'table' && self::listTable($template) ? end($rows) : null);
            if (is_array($source) && self::answered($source, $child)) {
                return true;
            }
        }

        return false;
    }
}
