<?php

namespace Tests\Feature\AiAssistance;

use App\Models\User;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistant;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistantScenarios;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateDraft;
use App\Modules\AiAssistance\Domain\Contracts\AiAgentGateway;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScriptedAiAgentGateway;
use Tests\TestCase;

class TemplateAssistantScenariosTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITIES = [
        'listar_variables', 'duplicar_campo', 'duplicar_seccion', 'mover_campo',
        'crear_tabla_repetible', 'mostrar_solo_si', 'cambiar_responsable_campo', 'usar_preset', 'renumerar', 'preguntar',
    ];

    private SyllabusTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
        $this->postJson(route('api.v1.admin.template.store'), ['official' => true])->assertCreated();
        $this->template = SyllabusTemplate::query()->firstOrFail();
    }

    public function test_every_scenario_passes_through_the_real_tools_with_its_script(): void
    {
        $harness = new TemplateAssistantScenarios;
        $scenarios = $harness->load();
        $before = TemplateDraft::fingerprint($this->template);

        $this->assertGreaterThanOrEqual(30, count($scenarios));
        $called = [];
        foreach ($scenarios as $number => $scenario) {
            $steps = [];
            foreach ($scenario['turnos'] as $turn) {
                $this->assertIsArray($turn['guion'] ?? null, "Escenario {$scenario['nombre']}: cada turno necesita su guion.");
                $this->assertIsArray($turn['espera'] ?? null, "Escenario {$scenario['nombre']}: cada turno necesita lo que se espera.");
                foreach ($turn['guion']['pasos'] as $step) {
                    $steps[] = ScriptedAiAgentGateway::calls(...array_map(fn (array $call): array => [$call['funcion'], $call['argumentos'] ?? []], $step));
                }
            }
            app()->instance(AiAgentGateway::class, new ScriptedAiAgentGateway($steps));

            $results = $harness->run(app(TemplateAssistant::class), $this->template->fresh(), $scenario, scripted: true);

            foreach ($results as $result) {
                $this->assertSame([], $result['fallas'], 'Escenario '.($number + 1)." «{$scenario['nombre']}», turno {$result['turno']}.");
                foreach ($result['outcome']->trace as $entry) {
                    $called[$entry['funcion']] = true;
                }
            }
        }

        $this->assertSame([], array_values(array_diff(self::CAPABILITIES, array_keys($called))));
        $this->assertSame($before, TemplateDraft::fingerprint($this->template->fresh()));
    }

    public function test_a_turn_that_misses_its_expectations_is_reported(): void
    {
        app()->instance(AiAgentGateway::class, new ScriptedAiAgentGateway([
            ScriptedAiAgentGateway::call('ver_campo', ['clave' => 'no_existe']),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
        ]));
        $scenario = ['nombre' => 'Falla', 'turnos' => [[
            'mensaje' => 'Duplica la descripción',
            'espera' => ['resultado' => 'propuesta', 'funciones' => ['duplicar_campo'], 'cambios' => ['(copia)'], 'mensaje' => ['copia|duplicado']],
            'guion' => ['pasos' => [], 'errores' => ['No existe la sección']],
        ]]];

        $results = (new TemplateAssistantScenarios)->run(app(TemplateAssistant::class), $this->template->fresh(), $scenario, scripted: true);

        $this->assertSame([
            'Se esperaba «propuesta» y fue «respuesta».',
            'No usó con éxito: duplicar_campo.',
            'Ningún cambio contiene «(copia)».',
            'El mensaje no menciona «copia|duplicado».',
            'Ninguna función devolvió el error «No existe la sección».',
        ], $results[0]['fallas']);
        $this->assertSame(0.0, TemplateAssistantScenarios::passRate($results));
    }

    public function test_the_trial_command_evaluates_the_scenarios_and_prints_the_pass_rate(): void
    {
        config(['ai.driver' => 'baseline']);

        $this->artisan('template:assistant-trial', ['--escenarios' => TemplateAssistantScenarios::FILE, '--solo' => ['1']])
            ->expectsOutputToContain('Turnos que cumplen lo esperado:')
            ->assertSuccessful();
        $this->assertSame(TemplateDraft::fingerprint($this->template), TemplateDraft::fingerprint($this->template->fresh()));
    }
}
