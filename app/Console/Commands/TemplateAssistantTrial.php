<?php

namespace App\Console\Commands;

use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistant;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistantOutcome;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Console\Command;

class TemplateAssistantTrial extends Command
{
    protected $signature = 'template:assistant-trial
        {--pedido=* : Pedido a probar; si no se indica, se leen los del archivo}
        {--archivo=resources/ai/plantilla/pruebas.txt : Archivo con un pedido por línea}';

    protected $description = 'Prueba el asistente de IA de la plantilla con pedidos reales sin guardar nada';

    public function handle(TemplateAssistant $assistant): int
    {
        $template = SyllabusTemplate::query()->first();
        if ($template === null) {
            $this->error('No hay plantilla institucional.');

            return self::FAILURE;
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
