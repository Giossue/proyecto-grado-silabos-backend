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
