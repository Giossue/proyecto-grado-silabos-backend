<?php

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Application\TemplateContext;
use Illuminate\Validation\ValidationException;

final class FieldResponse
{
    private const ANSWER = 'answer';

    private const CAREER = 'career';

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
        /** @var array<string, mixed> $template */
        $template = ResponseKind::withoutOwnOptions(self::template(FieldDocument::normalize($template)));
        if (self::blank($document)) {
            return $template;
        }
        if (! self::matches($template, $document)) {
            throw ValidationException::withMessages([$attribute => 'Complete solo los espacios de respuesta. El texto, los encabezados y la estructura los define Administración.']);
        }
        /** @var array<string, mixed> $document */
        $document = ResponseKind::withoutOwnOptions($document);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function career(array $definition, mixed $value, string $attribute = 'value', bool $saving = true): array
    {
        if ($saving) {
            self::within($attribute, fn () => ResponseKind::assertEnoughOptions($value));
        }
        $document = FieldDocument::normalize($value, $attribute);
        $template = self::template(FieldDocument::normalize($definition));
        if (self::blank($document)) {
            return $template;
        }
        if (! self::enabled()) {
            return self::withoutBlankLines($document);
        }
        if ($saving) {
            /** @var array<string, mixed> $document */
            $document = ResponseKind::withoutOwnOptions($document);
        }
        $document = self::within($attribute, fn (): array => self::adopt($template, $document, $saving));
        if (! self::matches($template, $document, self::CAREER)) {
            throw ValidationException::withMessages([$attribute => 'Complete solo los espacios de respuesta. El texto, los encabezados y la estructura los define Administración.']);
        }

        return self::seal($template, $document);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function templateFor(array $definition, mixed $stored): array
    {
        if (! FreeText::isDocument($stored)) {
            return $definition;
        }
        try {
            return self::career($definition, $stored, saving: false);
        } catch (ValidationException) {
            return $definition;
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function keepOwnOptions(array $definition, array $document, mixed $stored, string $attribute = 'value'): array
    {
        if (! self::enabled() || ! FreeText::isDocument($stored)) {
            return $document;
        }
        $template = self::template(FieldDocument::normalize($definition));

        return self::within($attribute, fn (): array => self::ownFrom($template, $document, self::templateFor($definition, $stored)));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    public static function followTemplate(array $definition, mixed $stored): ?array
    {
        if (! FreeText::isDocument($stored)) {
            return null;
        }
        $document = FieldDocument::normalize($stored);
        if (! self::enabled()) {
            return $document;
        }

        return self::adopt(self::template(FieldDocument::normalize($definition)), $document, false);
    }

    /** @param array<string, mixed> $definition */
    public static function writable(array $definition): bool
    {
        return self::spaces(self::template(FieldDocument::normalize($definition)));
    }

    /** @param array<string, mixed> $template */
    public static function open(array $template): bool
    {
        $type = $template['type'] ?? '';
        if ($type === 'tableHeader') {
            return false;
        }
        if ($type === ResponseKind::NODE) {
            return ! ResponseKind::answered($template['attrs'] ?? []);
        }

        return self::blank($template) || $type === 'documentBlock'
            || array_any($template['content'] ?? [], fn (array $child): bool => self::open($child));
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

    /** @param array<string, mixed> $template */
    public static function asksForAnswer(array $template): bool
    {
        $type = $template['type'] ?? '';
        if ($type === 'tableHeader') {
            return false;
        }
        if ($type === ResponseKind::NODE) {
            $attrs = $template['attrs'] ?? [];

            return ResponseKind::required($attrs) && ! ResponseKind::answered($attrs);
        }

        return self::blank($template) || $type === 'documentBlock'
            || array_any($template['content'] ?? [], fn (array $child): bool => self::asksForAnswer($child));
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
    private static function spaces(array $node): bool
    {
        if (($node['type'] ?? '') === 'tableHeader') {
            return false;
        }

        return self::blank($node) || ($node['type'] ?? '') === ResponseKind::NODE
            || array_any($node['content'] ?? [], fn (array $child): bool => self::spaces($child));
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function seal(array $template, array $value): array
    {
        $type = $template['type'] ?? '';
        if (in_array($type, ['tableHeader', 'documentBlock', ResponseKind::NODE], true)) {
            return $value;
        }
        if (self::blank($template)) {
            return self::blank($value) ? $value : ResponseKind::unanswered(self::withoutBlankLines($value));
        }
        $expected = $template['content'] ?? [];
        $actual = $value['content'] ?? [];
        if ($type === 'table' && self::listTable($template)) {
            $row = end($expected);
            while (count($expected) < count($actual)) {
                $expected[] = $row;
            }
        }
        if ($expected === [] || count($expected) !== count($actual)) {
            return $value;
        }

        return [...$value, 'content' => array_map(fn (array $child, array $current): array => self::seal($child, $current), $expected, $actual)];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $check
     * @return T
     */
    private static function within(string $attribute, callable $check): mixed
    {
        try {
            return $check();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$attribute => collect($exception->errors())->flatten()->all()]);
        }
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function adopt(array $template, array $value, bool $saving): array
    {
        $type = $template['type'] ?? '';
        if ($type !== ($value['type'] ?? '')) {
            return $value;
        }
        if ($type === ResponseKind::NODE) {
            return [...$value, 'attrs' => ResponseKind::ownOptions($template['attrs'] ?? [], $value['attrs'] ?? [], $saving)];
        }
        if (in_array($type, ['tableHeader', 'documentBlock'], true) || self::blank($template)) {
            return $value;
        }
        $expected = $template['content'] ?? [];
        $actual = $value['content'] ?? [];
        if ($type === 'table' && self::listTable($template)) {
            $row = end($expected);
            while (count($expected) < count($actual)) {
                $expected[] = $row;
            }
        }
        if ($expected === [] || count($expected) !== count($actual)) {
            return $value;
        }

        return [...$value, 'content' => array_map(fn (array $child, array $current): array => self::adopt($child, $current, $saving), array_values($expected), array_values($actual))];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $own
     * @return array<string, mixed>
     */
    private static function ownFrom(array $template, array $value, array $own): array
    {
        $type = $template['type'] ?? '';
        if ($type !== ($value['type'] ?? '') || $type !== ($own['type'] ?? '')) {
            return $value;
        }
        if ($type === ResponseKind::NODE) {
            $attrs = $value['attrs'] ?? [];
            $options = $own['attrs']['options'] ?? [];
            if (($own['attrs'][ResponseKind::OWN_OPTIONS] ?? null) !== true || ($attrs['options'] ?? []) !== ($template['attrs']['options'] ?? [])) {
                return $value;
            }

            return [...$value, 'attrs' => ResponseKind::attrs([...$attrs, 'options' => $options, ResponseKind::OWN_OPTIONS => true])];
        }
        if (in_array($type, ['tableHeader', 'documentBlock'], true) || self::blank($template)) {
            return $value;
        }
        $expected = $template['content'] ?? [];
        $actual = $value['content'] ?? [];
        $mine = $own['content'] ?? [];
        if ($type === 'table' && self::listTable($template) && $mine !== []) {
            $row = end($expected);
            $last = end($mine);
            while (count($expected) < count($actual)) {
                $expected[] = $row;
            }
            while (count($mine) < count($actual)) {
                $mine[] = $last;
            }
            $mine = array_slice($mine, 0, count($actual));
        }
        if ($expected === [] || count($expected) !== count($actual) || count($mine) !== count($actual)) {
            return $value;
        }

        return [...$value, 'content' => array_map(fn (array $child, array $current, array $ownChild): array => self::ownFrom($child, $current, $ownChild), array_values($expected), array_values($actual), array_values($mine))];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function withoutBlankLines(array $node): array
    {
        $type = $node['type'] ?? '';
        if (! is_array($node['content'] ?? null) || $type === 'paragraph') {
            return $node;
        }
        $children = array_values($node['content']);
        if ($type === 'listItem') {
            if (array_all($children, fn (array $child): bool => ($child['type'] ?? '') === 'paragraph' && self::blank($child))) {
                return [...$node, 'content' => []];
            }
            $first = array_shift($children);
            $children = [$first, ...array_filter($children, fn (array $child): bool => ! (($child['type'] ?? '') === 'paragraph' && self::blank($child)))];
        } elseif (! in_array($type, ['table', 'tableRow'], true)) {
            $children = array_filter($children, fn (array $child): bool => ! (($child['type'] ?? '') === 'paragraph' && self::blank($child)));
        }
        $children = array_values(array_filter(
            array_map(fn (array $child): array => self::withoutBlankLines($child), $children),
            fn (array $child): bool => ! in_array($child['type'] ?? '', ['listItem', 'bulletList', 'orderedList'], true) || ($child['content'] ?? []) !== [],
        ));
        if ($children === [] && in_array($type, ['doc', 'tableCell', 'tableHeader'], true)) {
            $children = [['type' => 'paragraph']];
        }

        return [...$node, 'content' => $children];
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
            return ResponseKind::answered($template['attrs'] ?? []) ? $template : [...$template, 'attrs' => ResponseKind::carry($template['attrs'] ?? [], $value['attrs'] ?? [])];
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
    private static function matches(array $template, array $value, string $mode = self::ANSWER): bool
    {
        if (($template['type'] ?? '') !== ($value['type'] ?? '')) {
            return false;
        }
        if (($template['type'] ?? '') === 'tableHeader') {
            return $template === $value;
        }
        if (($template['type'] ?? '') === ResponseKind::NODE) {
            if (($template['marks'] ?? []) !== ($value['marks'] ?? [])) {
                return false;
            }

            return $mode === self::ANSWER && ResponseKind::answered($template['attrs'] ?? [])
                ? ResponseKind::sameAnswer($template['attrs'] ?? [], $value['attrs'] ?? [])
                : ResponseKind::sameQuestion($template['attrs'] ?? [], $value['attrs'] ?? [], $mode === self::CAREER);
        }
        if (($template['type'] ?? '') === 'documentBlock') {
            return ($template['attrs']['kind'] ?? '') === ($value['attrs']['kind'] ?? '')
                && ($template['attrs']['props'] ?? []) === ($value['attrs']['props'] ?? []);
        }
        if (($template['attrs'] ?? []) !== ($value['attrs'] ?? [])) {
            return false;
        }
        if (self::blank($template)) {
            return $mode === self::CAREER ? self::careerOnly($value) : self::answerOnly($value);
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
            if (! self::matches($child, $actual[$index], $mode)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $node */
    private static function careerOnly(array $node): bool
    {
        return ! in_array($node['type'] ?? '', ['documentBlock', 'variable'], true)
            && array_all($node['content'] ?? [], fn (array $child): bool => self::careerOnly($child));
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
            return ! ResponseKind::answered($template['attrs'] ?? []) && ResponseKind::answered($value['attrs'] ?? []);
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
