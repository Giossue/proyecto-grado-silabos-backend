<?php

namespace App\Modules\Configuration\Domain;

use Illuminate\Validation\ValidationException;

/**
 * Texto libre del docente (I-156): párrafos, negrita y cursiva, saltos de línea,
 * listas con viñetas o numeradas y tablas simples. Se guarda como documento JSON en el
 * valor del campo; nunca admite marcas de la plantilla ni estilos de celda.
 */
final class FreeText
{
    private const MAX_NODES = 5000;

    private const MAX_DEPTH = 12;

    private const MAX_CHARACTERS = 50000;

    private const CHILDREN = [
        'doc' => ['paragraph', 'bulletList', 'orderedList', 'table'],
        'paragraph' => ['text', 'hardBreak'],
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        'listItem' => ['paragraph', 'bulletList', 'orderedList'],
        'table' => ['tableRow'],
        'tableRow' => ['tableCell', 'tableHeader'],
        'tableCell' => ['paragraph', 'bulletList', 'orderedList'],
        'tableHeader' => ['paragraph', 'bulletList', 'orderedList'],
    ];

    public static function isDocument(mixed $value): bool
    {
        return is_array($value) && ($value['type'] ?? null) === 'doc';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function normalize(mixed $value, string $attribute = 'value'): array
    {
        if (! self::isDocument($value)) {
            self::fail($attribute, 'El texto libre no tiene un formato válido.');
        }
        $count = 0;
        $characters = 0;
        /** @var array<string, mixed> $value */
        $document = self::node($value, null, 0, $count, $characters, $attribute);
        if ($characters > self::MAX_CHARACTERS) {
            self::fail($attribute, 'El texto libre supera los 50 000 caracteres.');
        }

        return $document;
    }

    /** @param array<string, mixed> $document */
    public static function isEmpty(array $document): bool
    {
        return trim(self::plainText($document)) === '';
    }

    /**
     * Texto sin formato para la IA y el resumen del expediente: una línea por párrafo,
     * elemento de lista o fila de tabla.
     *
     * @param  array<string, mixed>  $node
     */
    public static function plainText(array $node): string
    {
        $type = $node['type'] ?? null;
        if ($type === 'text') {
            return is_string($node['text'] ?? null) ? $node['text'] : '';
        }
        if ($type === 'hardBreak') {
            return "\n";
        }
        $children = array_map(
            fn (mixed $child): string => is_array($child) ? self::plainText($child) : '',
            is_array($node['content'] ?? null) ? $node['content'] : [],
        );
        if ($type === 'tableRow') {
            return implode(' | ', $children);
        }
        if ($type === 'listItem') {
            return '• '.implode("\n", $children);
        }

        return implode($type === 'paragraph' ? '' : "\n", $children);
    }

    /**
     * Bloques del documento para insertarlos en la hoja del sílabo.
     *
     * @return list<array<string, mixed>>
     */
    public static function blocks(mixed $value): array
    {
        if (! self::isDocument($value)) {
            return [];
        }
        $content = $value['content'] ?? [];

        return is_array($content) ? array_values(array_filter($content, is_array(...))) : [];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function node(array $raw, ?string $parent, int $depth, int &$count, int &$characters, string $attribute): array
    {
        $type = $raw['type'] ?? null;
        $allowed = $parent === null ? ['doc'] : self::CHILDREN[$parent];
        if (! is_string($type) || ! in_array($type, $allowed, true)) {
            self::fail($attribute, 'El texto libre contiene un elemento no permitido.');
        }
        if (++$count > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            self::fail($attribute, 'El texto libre es demasiado extenso o anidado.');
        }
        if ($type === 'text') {
            $text = $raw['text'] ?? null;
            if (! is_string($text) || $text === '') {
                self::fail($attribute, 'El texto libre contiene un texto vacío.');
            }
            $characters += mb_strlen($text);
            $marks = [];
            foreach (is_array($raw['marks'] ?? null) ? $raw['marks'] : [] as $mark) {
                $name = is_array($mark) ? ($mark['type'] ?? null) : null;
                if (in_array($name, ['bold', 'italic'], true)) {
                    $marks[] = ['type' => $name];
                }
            }

            return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
        }
        if ($type === 'hardBreak') {
            return ['type' => 'hardBreak'];
        }
        $node = ['type' => $type];
        if (in_array($type, ['tableCell', 'tableHeader'], true)) {
            $attrs = is_array($raw['attrs'] ?? null) ? $raw['attrs'] : [];
            $node['attrs'] = [
                'colspan' => self::span($attrs['colspan'] ?? 1, $attribute),
                'rowspan' => self::span($attrs['rowspan'] ?? 1, $attribute),
            ];
        }
        $content = [];
        foreach (is_array($raw['content'] ?? null) ? $raw['content'] : [] as $child) {
            if (! is_array($child)) {
                self::fail($attribute, 'El texto libre contiene un elemento no permitido.');
            }
            $content[] = self::node($child, $type, $depth + 1, $count, $characters, $attribute);
        }
        // Una lista, tabla o celda sin contenido no se puede dibujar; un párrafo vacío sí.
        if ($content === [] && $type !== 'paragraph') {
            if ($type === 'doc') {
                return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
            }
            self::fail($attribute, 'El texto libre contiene una lista o tabla vacía.');
        }
        if ($content !== []) {
            $node['content'] = $content;
        }

        return $node;
    }

    private static function span(mixed $value, string $attribute): int
    {
        if (! is_int($value) || $value < 1 || $value > 20) {
            self::fail($attribute, 'Una celda del texto libre no es válida.');
        }

        return $value;
    }

    private static function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
