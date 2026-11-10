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

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function setup(array $definition, mixed $value, string $attribute = 'value'): array
    {
        $document = ResponseKind::unanswered(FieldDocument::normalize($value, $attribute));
        if (! self::enabled() || ! self::matches(self::template(FieldDocument::normalize($definition)), $document, true)) {
            throw ValidationException::withMessages([$attribute => 'En los campos del docente solo puede elegir el tipo de respuesta y escribir sus opciones. El texto, los encabezados y la estructura los define Administración.']);
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function templateFor(array $definition, mixed $setup): array
    {
        if (! FreeText::isDocument($setup)) {
            return $definition;
        }
        try {
            return self::setup($definition, $setup);
        } catch (ValidationException) {
            return $definition;
        }
    }

    /** @param array<string, mixed> $definition */
    public static function configurable(array $definition): bool
    {
        return self::enabled() && self::setupSlots(self::template(FieldDocument::normalize($definition)));
    }

    /**
     * @param  array<string, mixed>  $template
     */
    public static function fits(array $template, mixed $value): bool
    {
        try {
            return self::matches(self::template(FieldDocument::normalize($template)), FieldDocument::normalize($value));
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    public static function carried(array $template, mixed $value): array
    {
        if (! FreeText::isDocument($value)) {
            return $template;
        }
        try {
            $current = self::template(FieldDocument::normalize($template));
            /** @var array<string, mixed> $value */
            $document = self::carry($current, FieldDocument::normalize($value));
        } catch (ValidationException) {
            return $template;
        }

        return self::matches($current, $document) ? $document : $template;
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
            && array_all($last['content'], fn (array $cell): bool => self::slot($cell) && ($cell['attrs']['rowspan'] ?? 1) === 1);
    }

    /** @param array<string, mixed> $cell */
    private static function slot(array $cell): bool
    {
        if (self::blank($cell)) {
            return true;
        }
        $paragraphs = $cell['content'] ?? [];
        $inlines = array_merge(...array_map(fn (array $child): array => array_values($child['content'] ?? []), array_values($paragraphs)));

        return ($cell['type'] ?? '') === 'tableCell' && $inlines !== []
            && array_all($paragraphs, fn (array $child): bool => ($child['type'] ?? '') === 'paragraph')
            && array_all($inlines, fn (array $inline): bool => ($inline['type'] ?? '') === ResponseKind::NODE);
    }

    /** @param array<string, mixed> $node */
    private static function setupSlots(array $node): bool
    {
        if (($node['type'] ?? '') === 'tableHeader') {
            return false;
        }
        if (($node['type'] ?? '') === ResponseKind::NODE) {
            return ResponseKind::needsOptions($node['attrs'] ?? []);
        }

        return self::blank($node) || array_any($node['content'] ?? [], fn (array $child): bool => self::setupSlots($child));
    }

    /** @param array<string, mixed> $node */
    private static function setupOnly(array $node): bool
    {
        $content = $node['content'] ?? [];
        if (($node['type'] ?? '') === 'paragraph') {
            return $content === [] || (count($content) === 1 && ($content[0]['type'] ?? '') === ResponseKind::NODE);
        }

        return array_all($content, fn (array $child): bool => ($child['type'] ?? '') === 'paragraph' && self::setupOnly($child));
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function carry(array $template, array $value): array
    {
        $type = $template['type'] ?? '';
        if ($type !== ($value['type'] ?? '') || $type === 'tableHeader') {
            return $template;
        }
        if ($type === ResponseKind::NODE) {
            return [...$template, 'attrs' => ResponseKind::carry($template['attrs'] ?? [], $value['attrs'] ?? [])];
        }
        if ($type === 'documentBlock') {
            return self::matches($template, $value) ? $value : $template;
        }
        if (self::blank($template)) {
            return self::answerOnly($value) && isset($value['content']) ? [...$template, 'content' => $value['content']] : $template;
        }
        $expected = $template['content'] ?? [];
        $actual = $value['content'] ?? [];
        if ($type === 'table' && self::listTable($template)) {
            $row = array_pop($expected);
            while (count($expected) < max(count($actual), count($template['content']))) {
                $expected[] = $row;
            }
        }
        if ($expected === [] || count($expected) !== count($actual)) {
            return $template;
        }

        return [...$template, 'content' => array_map(fn (array $child, array $current): array => self::carry($child, $current), $expected, $actual)];
    }

    /** @param array<string, mixed> $node */
    private static function hasSlots(array $node): bool
    {
        if (($node['type'] ?? '') === 'tableHeader') {
            return false;
        }

        return self::blank($node) || in_array($node['type'] ?? '', ['documentBlock', ResponseKind::NODE], true)
            || array_any($node['content'] ?? [], fn (array $child): bool => self::hasSlots($child));
    }

    /** @param array<string, mixed> $template
     * @param  array<string, mixed>  $value
     */
    private static function matches(array $template, array $value, bool $setup = false): bool
    {
        if (($template['type'] ?? '') !== ($value['type'] ?? '')) {
            return false;
        }
        if (($template['type'] ?? '') === 'tableHeader') {
            return $template === $value;
        }
        if (($template['type'] ?? '') === ResponseKind::NODE) {
            return ($template['marks'] ?? []) === ($value['marks'] ?? [])
                && ResponseKind::sameQuestion($template['attrs'] ?? [], $value['attrs'] ?? [], $setup);
        }
        if (($template['type'] ?? '') === 'documentBlock') {
            return $setup ? $template == $value : ($template['attrs']['kind'] ?? '') === ($value['attrs']['kind'] ?? '')
                && ($template['attrs']['props'] ?? []) === ($value['attrs']['props'] ?? []);
        }
        if (($template['attrs'] ?? []) !== ($value['attrs'] ?? [])) {
            return false;
        }
        if (self::blank($template)) {
            return $setup ? self::setupOnly($value) : self::answerOnly($value);
        }
        $expected = $template['content'] ?? [];
        $actual = $value['content'] ?? [];
        if (! $setup && ($template['type'] ?? '') === 'table' && self::listTable($template)) {
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
            if (! self::matches($child, $actual[$index], $setup)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $node */
    private static function answerOnly(array $node): bool
    {
        return ! in_array($node['type'] ?? '', ['documentBlock', 'variable', ResponseKind::NODE], true)
            && array_all($node['content'] ?? [], fn (array $child): bool => self::answerOnly($child));
    }

    /** @param array<string, mixed> $template
     * @param  array<string, mixed>  $value
     */
    private static function answered(array $template, array $value): bool
    {
        if (($template['type'] ?? '') === ResponseKind::NODE) {
            return ResponseKind::answered($value['attrs'] ?? []);
        }
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
