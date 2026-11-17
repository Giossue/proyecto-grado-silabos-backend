<?php

namespace App\Console\Commands;

use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistant;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistantOutcome;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistantScenarios;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Console\Command;
use RuntimeException;

class TemplateAssistantTrial extends Command
{
    protected $signature = 'template:assistant-trial
        {--pedido=* : Pedido a probar; si no se indica, se leen los del archivo}
        {--archivo=resources/ai/plantilla/pruebas.txt : Archivo con un pedido por línea}
        {--escenarios= : Archivo JSON con conversaciones de varios mensajes y lo que se espera de cada turno}
        {--solo=* : Números de escenario a correr}';

    protected $description = 'Prueba el asistente de IA de la plantilla con pedidos reales sin guardar nada';

    public function handle(TemplateAssistant $assistant): int
    {
        $template = SyllabusTemplate::query()->first();
        if ($template === null) {
            $this->error('No hay plantilla institucional.');

            return self::FAILURE;
        }
        $scenarios = $this->option('escenarios');
        if (is_string($scenarios) && $scenarios !== '') {
            return $this->scenarios($assistant, $template, $scenarios);
        }
        $requests = $this->requests();
        if ($requests === []) {
            $this->error('No hay pedidos para probar.');

            return self::FAILURE;
        }
        $summary = [];
        foreach ($requests as $number => $request) {
            $started = microtime(true);
            $outcome = $assistant->run($template->fresh(), $request);
            $seconds = round(microtime(true) - $started, 1);
            $this->report($number + 1, $request, $outcome, $seconds);
            $summary[] = [$number + 1, $outcome->status, $outcome->errorCode ?? '', $outcome->steps, $seconds, $outcome->usage['prompt'], $outcome->usage['cached'], $outcome->usage['completion']];
        }
        $this->newLine();
        $this->table(['#', 'Resultado', 'Error', 'Pasos', 'Segundos', 'Entrada', 'En caché', 'Salida'], $summary);

        return self::SUCCESS;
    }

    private function scenarios(TemplateAssistant $assistant, SyllabusTemplate $template, string $file): int
    {
        $harness = new TemplateAssistantScenarios;
        try {
            $scenarios = $harness->load($file);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $only = array_map('intval', (array) $this->option('solo'));
        $summary = [];
        $all = [];
        foreach ($scenarios as $index => $scenario) {
            $number = $index + 1;
            if ($only !== [] && ! in_array($number, $only, true)) {
                continue;
            }
            $this->newLine();
            $this->line("<options=bold;fg=cyan>Escenario {$number}: {$scenario['nombre']}</>");
            $started = microtime(true);
            $results = $harness->run($assistant, $template->fresh(), $scenario, false, function (array $result) use (&$started, &$summary, $number): void {
                $seconds = round(microtime(true) - $started, 1);
                $outcome = $result['outcome'];
                $this->report($result['turno'], $result['mensaje'], $outcome, $seconds);
                if ($result['aplicada']) {
                    $this->line('   <comment>→ Aplicada en el editor</comment>');
                }
                foreach ($result['fallas'] as $failure) {
                    $this->line("   <error>✗ {$failure}</error>");
                }
                $verdict = ! $result['evaluado'] ? '' : ($result['fallas'] === [] ? 'cumple' : 'no cumple');
                $summary[] = [$number, $result['turno'], $outcome->status, $outcome->errorCode ?? '', $outcome->steps, $seconds, $result['aplicada'] ? 'sí' : '', $verdict];
                $started = microtime(true);
            });
            array_push($all, ...$results);
        }
        $this->newLine();
        $this->table(['Escenario', 'Turno', 'Resultado', 'Error', 'Pasos', 'Segundos', 'Aplicada', 'Esperado'], $summary);
        $evaluated = array_filter($all, fn (array $result): bool => $result['evaluado']);
        $passed = array_filter($evaluated, fn (array $result): bool => $result['fallas'] === []);
        $this->line('Turnos que cumplen lo esperado: '.count($passed).' de '.count($evaluated).' ('.TemplateAssistantScenarios::passRate($all).' %)');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function requests(): array
    {
        $given = array_values(array_filter(array_map('trim', (array) $this->option('pedido')), fn (string $line): bool => $line !== ''));
        if ($given !== []) {
            return $given;
        }
        $option = $this->option('archivo');
        $file = base_path(is_string($option) ? $option : 'resources/ai/plantilla/pruebas.txt');
        $content = is_file($file) ? file_get_contents($file) : false;
        if ($content === false) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $content) ?: []), fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
    }

    private function report(int $number, string $request, TemplateAssistantOutcome $outcome, float $seconds): void
    {
        $this->newLine();
        $this->line("<options=bold>{$number}. {$request}</>");
        foreach ($outcome->trace as $entry) {
            $this->line("   paso {$entry['paso']} · {$entry['funcion']} {$entry['argumentos']} → {$entry['resultado']}");
        }
        $this->line("   <info>{$outcome->status}</info> en {$outcome->steps} pasos y {$seconds} s ({$outcome->gatewayVersion})");
        $this->line('   '.str_replace("\n", "\n   ", $outcome->message));
        foreach ($outcome->proposal['changes'] ?? [] as $change) {
            $this->line("   - {$change}");
        }
    }
}
