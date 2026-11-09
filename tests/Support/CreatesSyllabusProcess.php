<?php

namespace Tests\Support;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
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

    /** @return array<string, mixed> */
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
                    'unidades' => $this->plannedUnits(),
                    'evaluacion' => [],
                    'bibliografia' => ['libros' => [['autor' => 'Autor de prueba', 'titulo' => $text, 'anio' => '2020', 'ciudad' => 'Guaranda', 'editorial' => 'Editorial de prueba', 'isbn' => '9789563431063', 'codigo' => 'B-1']]],
                    default => [],
                };
            }
        }

        return $hasBlock ? FieldDocument::normalize($content) : $this->filledTemplateValue($field, $text);
    }

    /** @return array<string, mixed> */
    protected function plannedUnits(): array
    {
        $subject = Subject::query()->firstOrFail();
        $empty = ['contenidos' => 'Tema de prueba', 'acd_h' => '', 'ape_h' => '', 'aa_h' => ''];
        $first = [...$empty, 'acd_h' => (string) $subject->horas_ac, 'ape_h' => (string) $subject->horas_pae, 'aa_h' => (string) $subject->horas_aa];
        $units = [];
        foreach (range(0, 3) as $index) {
            $units[] = ['nombre' => 'Unidad de prueba', 'resultado' => 'Resultado de prueba', 'semanas' => $index === 0 ? [$first, $empty, $empty, $empty] : [$empty, $empty, $empty, $empty]];
        }

        return ['units' => $units];
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

    /**
     * Finaliza la convocatoria actual y abre la del período siguiente: la anterior pasa al historial.
     */
    protected function openNextConvocation(): SyllabusProcess
    {
        $current = SyllabusProcess::query()->orderByDesc('inicia_en')->firstOrFail();
        $current->update(['estado' => SyllabusProcess::STATE_CLOSED, 'finalizada_en' => now()]);
        $previous = $current->academicPeriod()->firstOrFail();
        $period = AcademicPeriod::query()->create([
            'codigo' => 'Período siguiente',
            'fecha_inicio' => $previous->fecha_fin->addDay(),
            'fecha_fin' => $previous->fecha_fin->addDays(150),
            'semanas_lectivas' => 16,
        ]);

        return SyllabusProcess::query()->create([
            'periodo_academico_id' => $period->id,
            'inicia_en' => max(now(), $current->inicia_en->addSecond()),
            'entrega_en' => now()->addMonth(),
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);
    }
}
