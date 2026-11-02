<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\FreeText;
use InvalidArgumentException;

final class FixedContent
{
    /** @return array<string, mixed> */
    public static function official(string $key): array
    {
        $document = match ($key) {
            'indicadores' => self::indicators(),
            'escala' => self::gradingScale(),
            'etica' => self::ethics(),
            'firmas' => self::signatures(),
            default => throw new InvalidArgumentException("No existe el contenido fijo «{$key}»."),
        };

        return FreeText::normalize($document);
    }

    /** @return array<string, mixed> */
    private static function indicators(): array
    {
        $rows = [
            ['Aprendizaje en contacto con el profesor', '2.5', '1.25'],
            ['Práctica-experimental de los aprendizajes', '2.0', '1.0'],
            ['Aprendizaje autónomo', '2.0', '1.0'],
            ['Evaluación parcial de los aprendizajes', '3.5', '1.75'],
        ];

        return self::doc([self::table([
            [self::header('INDICADORES', 1, 2), self::header('PRIMER PARCIAL', 2), self::header('SEGUNDO PARCIAL', 2)],
            [self::header('(PUNTOS)'), self::header('PONDERACIÓN'), self::header('(PUNTOS)'), self::header('PONDERACIÓN')],
            ...array_map(fn (array $row): array => [
                self::header($row[0]), self::cell($row[1].' PUNTOS'), self::cell($row[2]), self::cell($row[1].' PUNTOS'), self::cell($row[2]),
            ], $rows),
            [self::header('TOTAL'), self::cell('10 PUNTOS'), self::cell('5 PUNTOS'), self::cell('10 PUNTOS'), self::cell('5 PUNTOS')],
        ])]);
    }

    /** @return array<string, mixed> */
    private static function gradingScale(): array
    {
        $rows = [
            ['A', 'Igual a 10 o mayor o igual que 9', 'Excelente', 'Aprueba'],
            ['B', 'Menor que 9 o mayor o igual que 8', 'Muy Bueno', 'Aprueba'],
            ['C', 'Menor que 8 o mayor o igual que 7', 'Bueno', 'Aprueba'],
            ['D', 'Menor que 7 o mayor o igual que 6', 'Regular', 'Recuperación'],
            ['E', 'Menos de 6', 'Deficiente', 'No aprueba'],
        ];

        return self::doc([self::table([
            array_map(fn (string $text): array => self::header($text), ['ESCALA CUALITATIVA', 'ESCALA CUANTITATIVA GRADO Y POSGRADO', 'EQUIVALENCIAS', 'VALORACIÓN DE LA ASIGNATURA']),
            ...array_map(fn (array $row): array => array_map(fn (string $text): array => self::cell($text), $row), $rows),
        ])]);
    }

    /** @return array<string, mixed> */
    private static function ethics(): array
    {
        $commitments = [
            ['Puntualidad:', ' en las clases virtuales, considerando el plan emergente de la Universidad es necesario que exista el compromiso de asistir a las video conferencias ya que estas tendrán una duración aproximada de una hora, para lo cual es importante que el estudiante revise el material proporcionado por el profesor para poder avanzar en el aprendizaje.'],
            ['Acceso a la Video Conferencia:', ' Se utilizará la plataforma ZOOM los estudiantes deben tener instalada en sus computadoras o teléfonos celulares, se sugiere tener activado su video cámara mientras dure la sesión on-line de la clase, de igual manera permanecer silenciado su micrófono si no va a participar verbalmente.'],
            ['Registro de Asistencia:', ' Los estudiantes deben especificar su Nombre y Apellido el momento de ingresar a la conferencia on-line. De igual manera active o levante la mano del sistema para pedir la palabra.'],
            ['Respeto:', ' En las relaciones docente-estudiante y estudiante - estudiante será exigido en todo momento, garantizando un marco de respeto a la diversidad, de tal forma que se evidencie una convivencia armónica.'],
            ['Trabajos de investigación:', ' Se deberá incluir las citas y referencias de los autores consultados (de acuerdo a normativas aceptadas APA)'],
            ['Entrega de Trabajos, Talleres y Pruebas:', ' Deberán presentarse en las fechas y horas establecidas, caso contrario se calificará con una penalización.'],
            ['Presentación de PAE:', ' Las Prácticas de Aplicación y Experimentación se las desarrollará de acuerdo al formato suministrado por el profesor, y se lo entregará en las fechas establecidas.'],
            ['Trabajo en Equipo:', ' Reconocer y practicar el trabajo en equipo, la honestidad y la justicia en el tratamiento de la información, en el ámbito profesional y corporativo'],
        ];

        return self::doc([
            self::paragraph([self::text('Se aplicará el código de ética y políticas de acción afirmativa de la Universidad Estatal de Bolívar, además es importante compartir los principios de respeto mutuo dentro del ambiente virtual o salón de clase, entre los que se destacan los siguientes:')]),
            self::bullets(array_map(fn (array $item): array => self::item([self::paragraph([self::text($item[0], true), self::text($item[1])])]), $commitments)),
            self::paragraph([self::text('Dentro de los estándares de comportamiento', true), self::text(' se considera lo siguiente:')]),
            self::bullets([
                self::item([self::paragraph([self::text('La copia de tarea o trabajo ocasiona la pérdida total del porcentaje asignado a este rubro.')])]),
                self::item([self::paragraph([self::text('La copia del examen de medio término se sancionará con UNO sobre diez de calificación en este rubro')])]),
                self::item([self::paragraph([self::text('Todas las ideas, comentarios, frases o trabajos de otros autores deberán llevar el crédito correspondiente')])]),
                self::item([
                    self::paragraph([self::text('Se considera inadmisible lo siguiente:')]),
                    self::bullets([
                        self::item([self::paragraph([self::text('El plagio de frases u oraciones escritas, elementos gráficos, pruebas, ideas de trabajo de otros, publicadas o no publicadas')])]),
                        self::item([self::paragraph([self::text('La entrega o envío de trabajos o exámenes que no son propiedad del estudiante.')])]),
                        self::item([self::paragraph([self::text('La falsificación de datos.')])]),
                    ]),
                ]),
                self::item([self::paragraph([self::text('Las violaciones serán analizadas por el profesor, el director de carrera y el estudiante')])]),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private static function signatures(): array
    {
        $signature = fn (): array => self::cell(['', '', 'Firma:']);

        return self::doc([self::table([
            [$signature(), $signature(), $signature()],
            [self::cell(['Docente – Investigador/a']), self::cell(['Coordinación de Carrera']), self::cell(['Biblioteca'])],
            [self::strongHeader('Fecha de entrega:'), self::strongHeader('Fecha de revisión:'), self::strongHeader('Fecha de verificación:')],
        ])]);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @param  list<list<array<string, mixed>>>  $rows
     * @return array<string, mixed>
     */
    private static function table(array $rows): array
    {
        return ['type' => 'table', 'content' => array_map(fn (array $cells): array => ['type' => 'tableRow', 'content' => $cells], $rows)];
    }

    /**
     * @param  string|list<string>  $text
     * @return array<string, mixed>
     */
    private static function cell(string|array $text, int $colspan = 1, int $rowspan = 1): array
    {
        return self::tableCell('tableCell', $text, $colspan, $rowspan);
    }

    /** @return array<string, mixed> */
    private static function header(string $text, int $colspan = 1, int $rowspan = 1): array
    {
        return self::tableCell('tableHeader', $text, $colspan, $rowspan, '#C3D4EA');
    }

    /** @return array<string, mixed> */
    private static function strongHeader(string $text): array
    {
        return self::tableCell('tableHeader', $text, 1, 1, '#4F81BD', '#FFFFFF');
    }

    /**
     * @param  string|list<string>  $text
     * @return array<string, mixed>
     */
    private static function tableCell(string $type, string|array $text, int $colspan, int $rowspan, ?string $fill = null, ?string $color = null): array
    {
        $line = fn (string $line): array => $line === '' ? ['type' => 'paragraph'] : self::paragraph([
            $color === null ? self::text($line) : ['type' => 'text', 'text' => $line, 'marks' => [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['color' => $color]]]],
        ]);

        return [
            'type' => $type,
            'attrs' => ['colspan' => $colspan, 'rowspan' => $rowspan] + ($fill === null ? [] : ['backgroundColor' => $fill]),
            'content' => array_map($line, (array) $text),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function paragraph(array $content): array
    {
        return ['type' => 'paragraph', 'content' => $content];
    }

    /** @return array<string, mixed> */
    private static function text(string $text, bool $bold = false): array
    {
        return $bold ? ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'bold']]] : ['type' => 'text', 'text' => $text];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private static function bullets(array $items): array
    {
        return ['type' => 'bulletList', 'content' => $items];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function item(array $content): array
    {
        return ['type' => 'listItem', 'content' => $content];
    }
}
