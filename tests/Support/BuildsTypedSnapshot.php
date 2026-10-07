<?php

namespace Tests\Support;

use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Syllabus\Application\IdentificationCard;
use Illuminate\Support\Str;

/** Snapshot v7 de ejemplo con los cuatro tipos de campo (I-195, etapa 4). */
trait BuildsTypedSnapshot
{
    /** @return array<string, mixed> */
    private function typedSnapshot(): array
    {
        $table = [
            'columns' => [['key' => 'tema', 'label' => 'Tema'], ['key' => 'horas', 'label' => 'Horas']],
            'rows' => [['key' => 's1', 'label' => 'Semana uno'], ['key' => 's2', 'label' => 'Semana dos']],
        ];
        $field = fn (string $key, string $label, string $type, mixed $value, array $config = []): array => [
            'definition_id' => (string) Str::uuid(), 'key' => $key, 'label' => $label,
            'type' => $type, 'legacy' => false, 'config' => $config, 'value' => $value,
        ];

        return [
            'schema_version' => 7,
            'template_id' => '01a064a5-1d6d-7196-b189-05376ff0929d',
            'sections' => [
                ['key' => 'identificacion', 'title' => 'Identificación', 'fields' => [
                    $field('tarjeta', 'Identificación institucional', 'automatico', IdentificationCard::build(
                        [
                            'career' => ['name' => 'Software', 'faculty' => 'Facultad de Pruebas Integradas'],
                            'subject' => ['name' => 'Inteligencia Artificial', 'code' => 'SW-P7-037', 'cycle' => 7, 'prerequisites' => [], 'corequisites' => []],
                            'scheduled_subject' => ['period' => 'Marzo – Julio 2026', 'campus' => 'Matriz', 'modality' => 'Presencial'],
                        ],
                        ['A'], ['DOCENTE DE PRUEBA'], ['docente@ueb.edu.ec'],
                    ), ['source' => 'identification_card']),
                ], 'blocks' => []],
                ['key' => 'contenido', 'title' => 'Contenido', 'fields' => [
                    $field('descripcion', 'Descripción de la asignatura', 'texto', FreeText::fromText('Texto escrito por el docente')),
                    $field('perfil', 'Perfil de egreso', 'ficha', FreeText::fromText('Ficha heredada de la asignatura'), ['adjustable' => false]),
                    $field('distribucion', 'Distribución semanal', 'tabla', ['s1' => ['tema' => 'Introducción', 'horas' => '4'], 's2' => ['tema' => 'Agentes', 'horas' => '6']], $table),
                    $field('vacio', 'Campo vacío', 'texto', null),
                ], 'blocks' => []],
            ],
        ];
    }
}
