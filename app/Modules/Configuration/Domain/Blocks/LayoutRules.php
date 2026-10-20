<?php

namespace App\Modules\Configuration\Domain\Blocks;

final class LayoutRules
{
    public const DIRECTIVES = ['repeat', 'when', 'band'];

    private const SLOT_SOURCES = ['value', 'prop', 'item', 'counter', 'sum', 'count', 'index'];

    private const NAME = '/^[a-z][a-z0-9_]{0,40}$/';

    private const PATH = '/^(\{[a-z][a-z0-9_.]*\}|[a-z][a-z0-9_]*|\*)(\.(\{[a-z][a-z0-9_.]*\}|[a-z][a-z0-9_]*|\*))*$/';

    public function __construct(public readonly string $where) {}

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    public function directives(array $attrs, string $type): array
    {
        $clean = [];
        if (isset($attrs['repeat'])) {
            $clean['repeat'] = $this->repeat($attrs['repeat']);
        }
        if (isset($attrs['when'])) {
            $clean['when'] = Condition::define($attrs['when'], "{$this->where}.when");
        }
        if (isset($attrs['band'])) {
            if ($type !== 'tableRow' || ! is_string($attrs['band']) || preg_match('/^#[0-9a-fA-F]{6}$/D', $attrs['band']) !== 1) {
                BlockDefinitionError::fail("{$this->where}: «band» solo va en una fila (tr) y es un color #RRGGBB.");
            }
            $clean['band'] = strtoupper($attrs['band']);
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function repeat(mixed $raw): array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            BlockDefinitionError::fail("{$this->where}.repeat: escriba {\"value\": \"lista\"} o {\"prop\": \"lista\", \"as\": \"nombre\"}.");
        }
        $sources = array_values(array_intersect(['value', 'prop'], array_keys($raw)));
        if (count($sources) !== 1 || array_diff(array_keys($raw), ['value', 'prop', 'as', 'at_least']) !== []) {
            BlockDefinitionError::fail("{$this->where}.repeat: use «value» o «prop», y opcionalmente «as» y «at_least».");
        }
        $path = $raw[$sources[0]];
        if (! is_string($path) || preg_match(self::PATH, $path) !== 1 || str_contains($path, '*')) {
            BlockDefinitionError::fail("{$this->where}.repeat: «{$sources[0]}» debe ser una ruta sin *.");
        }
        $clean = [$sources[0] => $path];
        if (isset($raw['as'])) {
            if (! is_string($raw['as']) || preg_match(self::NAME, $raw['as']) !== 1) {
                BlockDefinitionError::fail("{$this->where}.repeat.as: use minúsculas, números y _.");
            }
            $clean['as'] = $raw['as'];
        }
        if ($sources[0] === 'prop' && ! isset($clean['as'])) {
            BlockDefinitionError::fail("{$this->where}.repeat: al repetir una propiedad, nombre cada elemento con «as».");
        }
        if (isset($raw['at_least'])) {
            if (! is_int($raw['at_least']) || $raw['at_least'] < 0 || $raw['at_least'] > 100) {
                BlockDefinitionError::fail("{$this->where}.repeat.at_least: debe ser un entero entre 0 y 100.");
            }
            $clean['at_least'] = $raw['at_least'];
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    public function slot(array $attrs): array
    {
        $sources = array_values(array_intersect(self::SLOT_SOURCES, array_keys($attrs)));
        if (count($sources) !== 1) {
            BlockDefinitionError::fail("{$this->where}: cada dato usa una sola fuente: ".implode(', ', self::SLOT_SOURCES).'.');
        }
        $source = $sources[0];
        $extra = array_diff(array_keys($attrs), [$source, 'option', 'mark']);
        if ($extra !== [] || ((isset($attrs['option']) || isset($attrs['mark'])) && $source !== 'value')) {
            BlockDefinitionError::fail("{$this->where}: «option» y «mark» solo acompañan a «value».");
        }
        if ($source === 'index') {
            if ($attrs['index'] !== true) {
                BlockDefinitionError::fail("{$this->where}: escriba \"index\": true.");
            }

            return ['index' => true];
        }
        $path = $attrs[$source];
        $pattern = $source === 'counter' ? self::NAME : self::PATH;
        if (! is_string($path) || preg_match($pattern, $path) !== 1 || (str_contains($path, '*') && ! in_array($source, ['sum', 'count'], true))) {
            BlockDefinitionError::fail("{$this->where}: la ruta del dato «{$source}» no es válida.");
        }
        $clean = [$source => $path];
        foreach (['option', 'mark'] as $key) {
            if (isset($attrs[$key])) {
                if (! is_string($attrs[$key]) || $attrs[$key] === '' || mb_strlen($attrs[$key]) > 40) {
                    BlockDefinitionError::fail("{$this->where}: «{$key}» debe ser un texto corto.");
                }
                $clean[$key] = $attrs[$key];
            }
        }

        return $clean;
    }
}
