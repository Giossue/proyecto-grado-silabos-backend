<?php

namespace App\Console\Commands;

use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplatePresentation;
use App\Modules\Configuration\Domain\Blocks\BlockDefinition;
use Illuminate\Console\Command;

class ExportTemplateConformance extends Command
{
    protected $signature = 'template:conformance {output : Carpeta de fixtures del frontend}';

    protected $description = 'Exporta la definición oficial y los casos de conformidad de bloques para las pruebas del frontend';

    public function handle(): int
    {
        $argument = $this->argument('output');
        $output = rtrim(is_string($argument) ? $argument : '', '/');
        if (! is_dir($output)) {
            $this->error("No existe la carpeta {$output}.");

            return self::FAILURE;
        }
        $definition = OfficialTemplate::definition();
        $week = fn (int $unit, int $number): array => ['contenidos' => "Contenido {$unit}.{$number}", 'acd_h' => '2', 'ape_h' => $number === 1 ? '1,5' : '1', 'aa_h' => '1', 'acd' => "Clase {$unit}.{$number}", 'ape' => '', 'aa' => 'Lectura', 'evaluacion' => ''];
        $columns = [['key' => 'autor', 'label' => 'Autor'], ['key' => 'titulo', 'label' => 'Título']];
        $cases = [
            ['kind' => 'unidades', 'props' => [], 'value' => ['units' => array_map(fn (int $unit): array => ['nombre' => "Unidad {$unit}", 'resultado' => "Resultado {$unit}", 'semanas' => array_map(fn (int $number): array => $week($unit, $number), range(1, $unit === 1 ? 5 : 3))], range(1, 4))], 'variables' => []],
            ['kind' => 'identificacion', 'props' => [], 'value' => ['disability' => ['answer' => 'si', 'type' => 'Visual', 'description' => 'Baja visión']], 'variables' => []],
            ['kind' => 'evaluacion', 'props' => [], 'value' => [], 'variables' => ['horas_practicas' => '16']],
            ['kind' => 'evaluacion', 'props' => [], 'value' => [], 'variables' => ['horas_practicas' => '0']],
            ['kind' => 'si_no', 'props' => ['question' => '¿Usa laboratorio?', 'columns' => $columns], 'value' => ['answer' => 'si', 'rows' => [['autor' => 'Ana', 'titulo' => 'Redes']]], 'variables' => []],
            ['kind' => 'si_no', 'props' => ['question' => '¿Sin responder?', 'columns' => []], 'value' => ['answer' => null, 'rows' => []], 'variables' => []],
            ['kind' => 'repetible', 'props' => ['label' => 'Lecturas', 'columns' => [$columns[0]]], 'value' => ['rows' => []], 'variables' => []],
        ];
        foreach ($cases as &$case) {
            $block = $definition->block($case['kind']) ?? [];
            $attrs = BlockDefinition::normalize($case['kind'], $block, $case);
            $case['value'] = $attrs['value'];
            $case['expanded'] = BlockDefinition::expand($block, $attrs, $case['variables']);
            $case['filled'] = BlockDefinition::filledText($block, $attrs);
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        file_put_contents("{$output}/template-definition.json", json_encode(TemplatePresentation::definition($definition), $flags)."\n");
        file_put_contents("{$output}/block-conformance.json", json_encode($cases, $flags)."\n");
        $this->info('Fixtures de conformidad actualizados.');

        return self::SUCCESS;
    }
}
