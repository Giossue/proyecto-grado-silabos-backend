<?php

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;

final class FieldLimit
{
    public const MIN = 1;

    public const MAX = 50000;

    public const INVALID = 'El límite de caracteres debe ser un número entero entre 1 y 50 000.';

    public const ONLY_CAREER = 'Solo un campo editable (Carrera) puede tener límite de caracteres.';

    public static function define(mixed $raw): int
    {
        if (! is_int($raw) || $raw < self::MIN || $raw > self::MAX) {
            TemplateDocument::fail(self::INVALID);
        }

        return $raw;
    }

    public static function allowed(string $responsible): bool
    {
        return FieldDefinition::responsible($responsible) === FieldDefinition::CAREER;
    }

    public static function format(int $number): string
    {
        return number_format($number, 0, ',', ' ');
    }

    public static function message(int $limit): string
    {
        return 'Este campo admite hasta '.self::format($limit).' caracteres.';
    }

    public static function exceeded(string $name, int $limit, int $count): string
    {
        return "«{$name}» tiene ".self::format($count).' caracteres y admite hasta '.self::format($limit).'. Recorte el texto antes de enviar.';
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $answers
     */
    public static function count(array $template, mixed $value, array $answers = []): int
    {
        if (! FreeText::isDocument($value)) {
            return 0;
        }
        /** @var array<string, mixed> $value */
        if (! FieldResponse::enabled()) {
            return self::written(['type' => 'doc', 'content' => [['type' => 'paragraph']]], $value, $answers);
        }

        return self::written(FieldResponse::template($template), $value, $answers);
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $answers
     */
    private static function written(array $template, array $value, array $answers): int
    {
        $type = $template['type'] ?? null;
        if ($type === ResponseKind::NODE) {
            $attrs = is_array($template['attrs'] ?? null) ? $template['attrs'] : [];
            $answer = is_array($value['attrs'] ?? null) ? ($value['attrs']['value'] ?? null) : null;
            if (($value['type'] ?? null) !== ResponseKind::NODE || ($attrs['kind'] ?? null) !== 'texto' || ResponseKind::answered($attrs)
                || ! ResponseCondition::visible($attrs, $answers) || ! is_string($answer)) {
                return 0;
            }

            return mb_strlen($answer);
        }
        if (in_array($type, ['documentBlock', 'tableHeader'], true) || ($template['type'] ?? null) !== ($value['type'] ?? null)) {
            return 0;
        }
        if (FieldResponse::blank($template)) {
            return self::text($value);
        }
        $rows = is_array($template['content'] ?? null) ? array_values($template['content']) : [];
        $list = $type === 'table' && FieldResponse::listTable($template);
        $count = 0;
        foreach (is_array($value['content'] ?? null) ? array_values($value['content']) : [] as $index => $child) {
            $source = $rows[$index] ?? ($list ? end($rows) : null);
            if (is_array($source) && is_array($child)) {
                $count += self::written($source, $child, $answers);
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function text(array $node): int
    {
        $type = $node['type'] ?? null;
        if ($type === 'text') {
            return is_string($node['text'] ?? null) ? mb_strlen($node['text']) : 0;
        }
        if ($type === 'documentBlock' || $type === ResponseKind::NODE) {
            return 0;
        }
        $count = 0;
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $count += is_array($child) ? self::text($child) : 0;
        }

        return $count;
    }
}
