<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use RuntimeException;

/**
 * @phpstan-type ScenarioCall array{funcion: string, argumentos?: array<string, mixed>}
 * @phpstan-type ScenarioTurn array{mensaje: string, aplicar?: bool, espera?: array{resultado?: string, funciones?: list<string>, cambios?: list<string>, mensaje?: list<string>}, guion?: array{pasos: list<list<ScenarioCall>>, errores?: list<string>}}
 * @phpstan-type Scenario array{nombre: string, convocatoria?: string, turnos: list<ScenarioTurn>}
 * @phpstan-type TurnResult array{turno: int, mensaje: string, outcome: TemplateAssistantOutcome, evaluado: bool, aplicada: bool, fallas: list<string>}
 */
final class TemplateAssistantScenarios
{
    public const FILE = 'resources/ai/plantilla/escenarios.json';

    /**
     * @return list<Scenario>
     */
    public function load(?string $file = null): array
    {
        $path = base_path($file ?? self::FILE);
        $raw = is_file($path) ? file_get_contents($path) : false;
        $scenarios = $raw === false ? null : json_decode($raw, true);
        if (! is_array($scenarios) || ! array_is_list($scenarios)) {
            throw new RuntimeException("No se pudo leer {$path}.");
        }
        foreach ($scenarios as $index => $scenario) {
            if (! is_array($scenario) || ! is_string($scenario['nombre'] ?? null) || ! is_array($scenario['turnos'] ?? null) || $scenario['turnos'] === []) {
                throw new RuntimeException('El escenario '.($index + 1).' necesita «nombre» y «turnos».');
            }
        }

        /** @var list<Scenario> $scenarios */
        return $scenarios;
    }

    /**
     * @param  Scenario  $scenario
     * @param  (callable(TurnResult): void)|null  $after
     * @return list<TurnResult>
     */
    public function run(TemplateAssistant $assistant, SyllabusTemplate $template, array $scenario, bool $scripted, ?callable $after = null): array
    {
        if (isset($scenario['convocatoria'])) {
            $assistant = $assistant->assumingProcess($scenario['convocatoria']);
        }
        $draft = TemplateDraft::fromTemplate($template)->payload()['sections'];
        $history = [];
        $last = null;
        $results = [];
        foreach ($scenario['turnos'] as $index => $turn) {
            $message = $turn['mensaje'];
            $outcome = $assistant->run($template, $message, $history, 'escenarios', $draft, $last);
            $applied = $outcome->proposal !== null && ($turn['aplicar'] ?? false) === true;
            if ($outcome->proposal !== null) {
                $last = ['estado' => $applied ? 'aplicada' : 'pendiente', 'cambios' => $outcome->proposal['changes'], 'secciones' => $outcome->proposal['payload']['sections'], 'bloques' => $outcome->proposal['payload']['block_changes'] ?? []];
            }
            if ($applied) {
                $draft = $outcome->proposal['payload']['sections'];
            }
            $history[] = ['role' => 'user', 'content' => $message];
            $history[] = ['role' => 'assistant', 'content' => $outcome->message];
            $result = [
                'turno' => $index + 1,
                'mensaje' => $message,
                'outcome' => $outcome,
                'evaluado' => isset($turn['espera']) || ($scripted && isset($turn['guion']['errores'])),
                'aplicada' => $applied,
                'fallas' => $this->failures($turn, $outcome, $scripted),
            ];
            $results[] = $result;
            if ($after !== null) {
                $after($result);
            }
        }

        return $results;
    }

    /**
     * @param  list<TurnResult>  $results
     */
    public static function passRate(array $results): float
    {
        $evaluated = array_values(array_filter($results, fn (array $result): bool => $result['evaluado']));
        if ($evaluated === []) {
            return 0.0;
        }

        return round(100 * count(array_filter($evaluated, fn (array $result): bool => $result['fallas'] === [])) / count($evaluated), 1);
    }

    /**
     * @param  ScenarioTurn  $turn
     * @return list<string>
     */
    private function failures(array $turn, TemplateAssistantOutcome $outcome, bool $scripted): array
    {
        $expected = $turn['espera'] ?? [];
        $failures = [];
        if (isset($expected['resultado']) && ! in_array($outcome->status, explode('|', $expected['resultado']), true)) {
            $failures[] = "Se esperaba «{$expected['resultado']}» y fue «{$outcome->status}».";
        }
        $succeeded = array_column(array_filter($outcome->trace, fn (array $entry): bool => $entry['resultado'] === 'ok'), 'funcion');
        $missing = array_values(array_diff($expected['funciones'] ?? [], $succeeded));
        if ($missing !== []) {
            $failures[] = 'No usó con éxito: '.implode(', ', $missing).'.';
        }
        foreach ($expected['cambios'] ?? [] as $change) {
            if (! $this->mentions($outcome->proposal['changes'] ?? [], $change)) {
                $failures[] = "Ningún cambio contiene «{$change}».";
            }
        }
        foreach ($expected['mensaje'] ?? [] as $words) {
            if (! $this->mentions([$outcome->message], $words)) {
                $failures[] = "El mensaje no menciona «{$words}».";
            }
        }
        if ($scripted) {
            $errors = array_column(array_filter($outcome->trace, fn (array $entry): bool => str_starts_with($entry['resultado'], 'error: ')), 'resultado');
            foreach ($turn['guion']['errores'] ?? [] as $error) {
                if (! $this->mentions($errors, $error)) {
                    $failures[] = "Ninguna función devolvió el error «{$error}».";
                }
            }
        }

        return $failures;
    }

    /**
     * @param  list<string>  $texts
     */
    private function mentions(array $texts, string $alternatives): bool
    {
        foreach (explode('|', $alternatives) as $alternative) {
            foreach ($texts as $text) {
                if (str_contains($this->plain($text), $this->plain($alternative))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function plain(string $text): string
    {
        return mb_strtolower(strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']));
    }
}
