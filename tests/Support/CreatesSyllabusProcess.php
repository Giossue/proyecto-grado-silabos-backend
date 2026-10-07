<?php

namespace Tests\Support;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Carbon\CarbonInterface;

trait CreatesSyllabusProcess
{
    protected function freeTextValue(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    /** Valor válido para una `tabla`: todas las celdas fijas con texto. */
    protected function filledTableValue(FieldDefinition $field): array
    {
        if ($field->fieldType()->key() === 'unidades') {
            return [['nombre' => 'Unidad de prueba', 'resultado' => 'Resultado de prueba', 'semanas' => [['contenidos' => 'Tema', 'acd_h' => '2', 'ape_h' => '1', 'aa_h' => '3', 'acd' => 'Clase', 'ape' => 'Guía', 'aa' => 'Lectura', 'evaluacion' => 'Foro']]]];
        }

        return array_map(
            fn (array $cells): array => array_map(fn (): string => 'Dato de prueba', $cells),
            $field->fieldType()->defaultValue($field->typeConfig()),
        );
    }

    protected function openSyllabusProcess(
        string $templateId,
        CarbonInterface|string|null $startsAt = null,
        CarbonInterface|string|null $dueAt = null,
    ): SyllabusProcess {
        $attributes = [
            'periodo_academico_id' => AcademicPeriod::query()->valueOrFail('id'),
            'inicia_en' => $startsAt ?? now()->subDay(),
            'entrega_en' => $dueAt ?? now()->addMonth(),
        ];
        $existing = SyllabusProcess::query()->inProgress()->first();

        if ($existing !== null) {
            $existing->update([...$attributes, 'estado' => SyllabusProcess::STATE_OPEN]);

            return $existing->fresh();
        }

        return SyllabusProcess::query()->create([
            ...$attributes,
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);
    }
}
