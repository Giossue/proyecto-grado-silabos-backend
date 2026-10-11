<?php

namespace Tests\Support;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Carbon\CarbonInterface;

trait CreatesSyllabusProcess
{
    protected function freeTextValue(string $text): array
    {
        return FieldDocument::normalize(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]]);
    }

    /**
     * Valor completo para un campo del docente: texto, o el bloque de la plantilla ya respondido.
     *
     * @return array<string, mixed>
     */
    protected function answeredValue(FieldDefinition $field, string $text): array
    {
        $content = FieldDocument::normalize($field->contenido);
        $hasBlock = false;
        foreach ($content['content'] as $index => $node) {
            if (($node['type'] ?? null) === 'documentBlock') {
                $hasBlock = true;
                $kind = $node['attrs']['kind'];
                $content['content'][$index]['attrs']['value'] = match ($kind) {
                    'identificacion' => ['disability' => ['answer' => 'no', 'type' => '', 'description' => '']],
                    'si_no' => ['answer' => 'no', 'rows' => []],
                    default => ['rows' => []],
                };
            }
        }

        return $hasBlock ? FieldDocument::normalize($content) : $this->freeTextValue($text);
    }

    protected function filledTableValue(FieldDefinition $field): array
    {
        return $this->freeTextValue('Unidad de prueba. Resultado de prueba. Horas escritas por el docente.');
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
