<?php

namespace App\Modules\Configuration\Domain;

final class DocumentBlock
{
    public const SI_NO = 'si_no';

    public const REPETIBLE = 'repetible';

    public const IDENTIFICACION = 'identificacion';

    public const UNIDADES = 'unidades';

    public const EVALUACION = 'evaluacion';

    public const KINDS = [self::SI_NO, self::REPETIBLE, self::IDENTIFICACION, self::UNIDADES, self::EVALUACION];

    public const UNITS = 4;

    public const WEEKS = 16;

    private const WEEK_TEXT = ['contenidos', 'acd', 'ape', 'aa', 'evaluacion'];

    private const WEEK_HOURS = ['acd_h', 'ape_h', 'aa_h'];

    public const SLOT_VARIABLES = ['discapacidad_si', 'discapacidad_no', 'discapacidad_tipo', 'discapacidad_descripcion'];

    /** @var array<string, mixed>|null */
    private static ?array $identificationTable = null;

    private const MAX_COLUMNS = 8;

    private const MAX_ROWS = 100;

    private const MAX_CELL = 1000;

    /**
     * @param  array<string, mixed>  $attrs
     * @return array{kind: string, props: array<string, mixed>, value: array<string, mixed>}
     */
    public static function normalize(array $attrs): array
    {
        $kind = $attrs['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            TemplateDocument::fail('El bloque no es de un tipo permitido.');
        }
        $props = self::props($kind, is_array($attrs['props'] ?? null) ? $attrs['props'] : []);

        return ['kind' => $kind, 'props' => $props, 'value' => self::value($kind, $props, $attrs['value'] ?? null)];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $variables
     * @return list<array<string, mixed>>
     */
    public static function expand(array $node, array $variables = []): array
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $kind = (string) ($attrs['kind'] ?? '');
        $props = is_array($attrs['props'] ?? null) ? $attrs['props'] : [];
        $value = is_array($attrs['value'] ?? null) ? $attrs['value'] : [];
        $columns = self::columnsOf($props);
        $rows = self::rowsOf($value);
        $nodes = [];
        if ($kind === self::IDENTIFICACION) {
            return [self::identificationTable()];
        }
        if ($kind === self::UNIDADES) {
            $week = 0;
            $units = new UnitsTable;
            $tables = [];
            foreach (self::unitsOf($value) as $index => $unit) {
                $tables[] = $units->table($unit, $index + 1, $week);
                $tables[] = ['type' => 'paragraph'];
            }

            return $tables;
        }
        if ($kind === self::EVALUACION) {
            return [self::evaluationTable(! self::withoutPractice($variables))];
        }
        if ($kind === self::SI_NO) {
            $answer = $value['answer'] ?? null;
            $nodes[] = self::paragraph((string) ($props['question'] ?? '').' '.match ($answer) {
                'si' => 'Sí',
                'no' => 'No',
                default => '',
            }, true);
            if ($answer === 'si' && $columns !== []) {
                $nodes[] = self::table($columns, $rows);
            }
        } elseif ($kind === self::REPETIBLE) {
            $nodes[] = self::table($columns, $rows);
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, string>
     */
    public static function variables(array $node): array
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        if (($attrs['kind'] ?? null) !== self::IDENTIFICACION) {
            return [];
        }
        $disability = is_array($attrs['value']['disability'] ?? null) ? $attrs['value']['disability'] : [];
        $answer = $disability['answer'] ?? null;

        return [
            'discapacidad_si' => $answer === 'si' ? 'X' : '',
            'discapacidad_no' => $answer === 'no' ? 'X' : '',
            'discapacidad_tipo' => is_string($disability['type'] ?? null) ? $disability['type'] : '',
            'discapacidad_descripcion' => is_string($disability['description'] ?? null) ? $disability['description'] : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function identificationTable(): array
    {
        if (self::$identificationTable === null) {
            $lines = file(resource_path('templates/bloque-identificacion.html'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $document = DocumentHtml::fromHtml(array_values($lines));
            self::$identificationTable = $document['content'][0];
        }

        return self::$identificationTable;
    }

    /**
     * Tabla de identificación con los datos del sistema ya resueltos; solo quedan como variables las casillas
     * que responde el docente, para que cada pantalla las dibuje como controles o como texto.
     *
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    public static function identificationLayout(array $variables): array
    {
        $visit = function (array $node) use (&$visit, $variables): array {
            if (($node['type'] ?? null) === 'variable' && ! in_array($node['attrs']['id'] ?? null, self::SLOT_VARIABLES, true)) {
                $text = $variables[$node['attrs']['id'] ?? ''] ?? '';

                return $text === '' ? ['type' => 'text', 'text' => ' '] : ['type' => 'text', 'text' => $text];
            }
            if (is_array($node['content'] ?? null)) {
                $node['content'] = array_map($visit, $node['content']);
            }

            return $node;
        };

        return $visit(self::identificationTable());
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public static function plainText(array $attrs): string
    {
        $props = is_array($attrs['props'] ?? null) ? $attrs['props'] : [];
        $value = is_array($attrs['value'] ?? null) ? $attrs['value'] : [];
        $kind = (string) ($attrs['kind'] ?? '');
        $lines = [];
        if ($kind === self::UNIDADES) {
            $cells = [];
            foreach (self::unitsOf($value) as $unit) {
                $cells[] = (string) ($unit['nombre'] ?? '').' '.($unit['resultado'] ?? '');
                foreach ($unit['semanas'] as $week) {
                    array_push($cells, ...array_map(fn (mixed $cell): string => is_string($cell) ? $cell : '', array_values($week)));
                }
            }

            return trim(implode(' ', array_filter(array_map('trim', $cells), fn (string $cell): bool => $cell !== '')));
        }
        if ($kind === self::EVALUACION) {
            return 'Evaluación de los aprendizajes';
        }
        if ($kind === self::IDENTIFICACION) {
            $disability = is_array($value['disability'] ?? null) ? $value['disability'] : [];
            $answer = $disability['answer'] ?? null;
            if ($answer !== 'si' && $answer !== 'no') {
                return '';
            }

            return trim('Estudiantes con discapacidad: '.($answer === 'si' ? 'Sí' : 'No').' '.($disability['type'] ?? '').' '.($disability['description'] ?? ''));
        }
        if ($kind === self::SI_NO) {
            $answer = $value['answer'] ?? null;
            if ($answer !== 'si' && $answer !== 'no') {
                return '';
            }
            $lines[] = trim((string) ($props['question'] ?? '').' '.($answer === 'si' ? 'Sí' : 'No'));
            if ($answer === 'no') {
                return $lines[0];
            }
        }
        foreach (self::rowsOf($value) as $row) {
            $cells = array_filter(array_map(fn (string $cell): string => trim($cell), array_values($row)), fn (string $cell): bool => $cell !== '');
            if ($cells !== []) {
                $lines[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $document
     */
    public static function sameStructure(array $template, array $document): bool
    {
        return self::skeleton($template) === self::skeleton($document);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array{kind: mixed, props: mixed}>
     */
    private static function skeleton(array $document): array
    {
        $blocks = [];
        foreach (is_array($document['content'] ?? null) ? $document['content'] : [] as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'documentBlock') {
                $blocks[] = ['kind' => $node['attrs']['kind'] ?? null, 'props' => $node['attrs']['props'] ?? null];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function props(string $kind, array $props): array
    {
        $clean = [];
        if (in_array($kind, [self::IDENTIFICACION, self::UNIDADES, self::EVALUACION], true)) {
            return [];
        }
        if ($kind === self::SI_NO) {
            $question = trim((string) ($props['question'] ?? ''));
            if ($question === '' || mb_strlen($question) > 300) {
                TemplateDocument::fail('La pregunta del bloque Sí/No es obligatoria y admite hasta 300 caracteres.');
            }
            $clean['question'] = $question;
        } else {
            $label = trim((string) ($props['label'] ?? ''));
            if (mb_strlen($label) > 120) {
                TemplateDocument::fail('El título de la lista repetible admite hasta 120 caracteres.');
            }
            $clean['label'] = $label;
        }
        $columns = [];
        $keys = [];
        foreach (is_array($props['columns'] ?? null) ? $props['columns'] : [] as $column) {
            $key = is_array($column) ? ($column['key'] ?? null) : null;
            $label = is_array($column) ? trim((string) ($column['label'] ?? '')) : '';
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,30}$/', $key) !== 1 || in_array($key, $keys, true) || $label === '' || mb_strlen($label) > 120) {
                TemplateDocument::fail('Las columnas del bloque necesitan una clave única y un nombre de hasta 120 caracteres.');
            }
            $keys[] = $key;
            $columns[] = ['key' => $key, 'label' => $label];
        }
        if (count($columns) > self::MAX_COLUMNS || ($kind === self::REPETIBLE && $columns === [])) {
            TemplateDocument::fail($kind === self::REPETIBLE ? 'La lista repetible necesita entre 1 y 8 columnas.' : 'El bloque admite hasta 8 columnas.');
        }
        $clean['columns'] = $columns;

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function value(string $kind, array $props, mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $value = [];
        if ($kind === self::EVALUACION) {
            return [];
        }
        if ($kind === self::UNIDADES) {
            return ['units' => self::cleanUnits($raw)];
        }
        if ($kind === self::IDENTIFICACION) {
            $input = is_array($raw['disability'] ?? null) ? $raw['disability'] : [];
            $answer = $input['answer'] ?? null;
            if ($answer !== null && ! in_array($answer, ['si', 'no'], true)) {
                TemplateDocument::fail('La respuesta sobre estudiantes con discapacidad no es válida.');
            }
            $text = function (mixed $text) use ($answer): string {
                if ($answer !== 'si') {
                    return '';
                }
                if (! is_string($text) || mb_strlen($text) > 500) {
                    TemplateDocument::fail('El texto sobre discapacidad admite hasta 500 caracteres.');
                }

                return $text;
            };

            return ['disability' => ['answer' => $answer, 'type' => $text($input['type'] ?? ''), 'description' => $text($input['description'] ?? '')]];
        }
        if ($kind === self::SI_NO) {
            $answer = $raw['answer'] ?? null;
            if ($answer !== null && ! in_array($answer, ['si', 'no'], true)) {
                TemplateDocument::fail('La respuesta del bloque Sí/No no es válida.');
            }
            $value['answer'] = $answer;
        }
        $keys = array_column(self::columnsOf($props), 'key');
        $rows = [];
        $input = array_is_list($raw['rows'] ?? []) && is_array($raw['rows'] ?? null) ? $raw['rows'] : [];
        if (count($input) > self::MAX_ROWS) {
            TemplateDocument::fail('El bloque admite hasta 100 filas.');
        }
        foreach ($input as $row) {
            $row = is_array($row) ? $row : [];
            $clean = [];
            foreach ($keys as $key) {
                $cell = $row[$key] ?? '';
                if (! is_string($cell) || mb_strlen($cell) > self::MAX_CELL) {
                    TemplateDocument::fail('Una celda del bloque supera los 1000 caracteres.');
                }
                $clean[$key] = $cell;
            }
            $rows[] = $clean;
        }
        if ($kind === self::SI_NO && ($value['answer'] ?? null) !== 'si') {
            $rows = [];
        }
        $value['rows'] = $rows;

        return $value;
    }

    /**
     * Horas repartidas en las unidades: ACD, APE y AA.
     *
     * @param  array<string, mixed>  $attrs
     * @return array{acd: float, ape: float, aa: float}
     */
    public static function unitHours(array $attrs): array
    {
        $totals = ['acd' => 0.0, 'ape' => 0.0, 'aa' => 0.0];
        $value = is_array($attrs['value'] ?? null) ? $attrs['value'] : [];
        foreach (self::unitsOf($value) as $unit) {
            foreach ($unit['semanas'] as $week) {
                foreach (['acd', 'ape', 'aa'] as $component) {
                    $totals[$component] += self::hours($week[$component.'_h'] ?? null);
                }
            }
        }

        return $totals;
    }

    public static function hours(mixed $text): float
    {
        return is_string($text) || is_numeric($text) ? (float) str_replace(',', '.', trim((string) $text)) : 0.0;
    }

    /**
     * Sin horas de práctico-experimental el reglamento de evaluación usa otro reparto de puntos.
     *
     * @param  array<string, string>  $variables
     */
    private static function withoutPractice(array $variables): bool
    {
        $hours = trim($variables['horas_practicas'] ?? '');

        return $hours !== '' && self::hours($hours) === 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function evaluationTable(bool $withPractice): array
    {
        $rows = $withPractice
            ? [['Aprendizaje en contacto con el docente', '2.5', '1.25'], ['Aprendizaje práctico-experimental', '2.0', '1.0'], ['Aprendizaje autónomo', '2.0', '1.0'], ['Evaluación parcial de los aprendizajes', '3.5', '1.75']]
            : [['Aprendizaje en contacto con el docente', '3.5', '1.75'], ['Aprendizaje autónomo', '3.0', '1.5'], ['Evaluación parcial de los aprendizajes', '3.5', '1.75']];
        $lines = ['<table>', '<tr><th rowspan="2">INDICADORES</th><th colspan="2">PRIMER PARCIAL</th><th colspan="2">SEGUNDO PARCIAL</th></tr>', '<tr><th>(PUNTOS)</th><th>PONDERACIÓN</th><th>(PUNTOS)</th><th>PONDERACIÓN</th></tr>'];
        foreach ($rows as [$name, $points, $weight]) {
            $lines[] = "<tr><th>{$name}</th><td>{$points} PUNTOS</td><td>{$weight}</td><td>{$points} PUNTOS</td><td>{$weight}</td></tr>";
        }
        $lines[] = '<tr><th>TOTAL</th><td>10 PUNTOS</td><td>5 PUNTOS</td><td>10 PUNTOS</td><td>5 PUNTOS</td></tr>';
        $lines[] = '</table>';

        return DocumentHtml::fromHtml($lines)['content'][0];
    }

    /**
     * Cuatro unidades que se reparten las 16 semanas del período; sin datos, cuatro semanas por unidad.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private static function cleanUnits(array $raw): array
    {
        $given = is_array($raw['units'] ?? null) && array_is_list($raw['units']);
        $input = $given ? $raw['units'] : [];
        if (count($input) > self::UNITS) {
            TemplateDocument::fail('La planificación admite '.self::UNITS.' unidades.');
        }
        $text = function (mixed $cell, string $what): string {
            if ($cell === null) {
                return '';
            }
            if (! is_string($cell) || mb_strlen($cell) > self::MAX_CELL) {
                TemplateDocument::fail("{$what} supera los 1000 caracteres.");
            }

            return $cell;
        };
        $total = 0;
        $units = [];
        for ($index = 0; $index < self::UNITS; $index++) {
            $unit = is_array($input[$index] ?? null) ? $input[$index] : [];
            $weeks = is_array($unit['semanas'] ?? null) && array_is_list($unit['semanas']) ? $unit['semanas'] : [];
            $count = $given ? count($weeks) : intdiv(self::WEEKS, self::UNITS);
            $total += $count;
            if ($total > self::WEEKS) {
                TemplateDocument::fail('Las unidades suman más de '.self::WEEKS.' semanas.');
            }
            $clean = [];
            for ($number = 0; $number < $count; $number++) {
                $week = is_array($weeks[$number] ?? null) ? $weeks[$number] : [];
                $row = [];
                foreach (self::WEEK_TEXT as $key) {
                    $row[$key] = $text($week[$key] ?? null, 'Una celda de la semana');
                }
                foreach (self::WEEK_HOURS as $key) {
                    $hours = trim($text($week[$key] ?? null, 'Las horas'));
                    if ($hours !== '' && preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $hours) !== 1) {
                        TemplateDocument::fail('Las horas por semana deben ser un número.');
                    }
                    $row[$key] = $hours;
                }
                $clean[] = $row;
            }
            $units[] = ['nombre' => $text($unit['nombre'] ?? null, 'El nombre de la unidad'), 'resultado' => $text($unit['resultado'] ?? null, 'El resultado de aprendizaje'), 'semanas' => $clean];
        }

        return $units;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public static function weekCount(array $attrs): int
    {
        $value = is_array($attrs['value'] ?? null) ? $attrs['value'] : [];

        return array_sum(array_map(fn (array $unit): int => count($unit['semanas']), self::unitsOf($value)));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<array<string, mixed>>
     */
    private static function unitsOf(array $value): array
    {
        $units = [];
        foreach (self::cleanUnits(['units' => is_array($value['units'] ?? null) ? $value['units'] : []]) as $unit) {
            $units[] = $unit;
        }

        return $units;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<array{key: string, label: string}>
     */
    private static function columnsOf(array $props): array
    {
        $columns = [];
        foreach (is_array($props['columns'] ?? null) ? $props['columns'] : [] as $column) {
            if (is_array($column) && is_string($column['key'] ?? null) && is_string($column['label'] ?? null)) {
                $columns[] = ['key' => $column['key'], 'label' => $column['label']];
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<array<string, string>>
     */
    private static function rowsOf(array $value): array
    {
        $rows = [];
        foreach (is_array($value['rows'] ?? null) ? $value['rows'] : [] as $row) {
            if (is_array($row)) {
                $rows[] = array_map(fn (mixed $cell): string => is_string($cell) ? $cell : '', $row);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function paragraph(string $text, bool $bold = false): array
    {
        $text = trim($text);

        return $text === '' ? ['type' => 'paragraph'] : [
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text] + ($bold ? ['marks' => [['type' => 'bold']]] : [])],
        ];
    }

    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array<string, string>>  $rows
     * @return array<string, mixed>
     */
    private static function table(array $columns, array $rows): array
    {
        $cell = fn (string $type, string $text): array => [
            'type' => $type,
            'attrs' => ['colspan' => 1, 'rowspan' => 1],
            'content' => [self::paragraph($text)],
        ];
        $content = [['type' => 'tableRow', 'content' => array_map(fn (array $column): array => $cell('tableHeader', $column['label']), $columns)]];
        foreach ($rows === [] ? [[]] : $rows as $row) {
            $content[] = ['type' => 'tableRow', 'content' => array_map(fn (array $column): array => $cell('tableCell', (string) ($row[$column['key']] ?? '')), $columns)];
        }

        return ['type' => 'table', 'content' => $content];
    }
}
