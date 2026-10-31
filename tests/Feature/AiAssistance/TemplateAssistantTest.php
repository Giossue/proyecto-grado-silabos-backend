<?php

namespace Tests\Feature\AiAssistance;

use App\Models\User;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistant;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistantOutcome;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateDraft;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateDraftValidator;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateTools;
use App\Modules\AiAssistance\Domain\Contracts\AiAgentGateway;
use App\Modules\AiAssistance\Domain\Data\AiAgentRequest;
use App\Modules\AiAssistance\Domain\Data\AiAgentStep;
use App\Modules\AiAssistance\Domain\Exceptions\AiContractException;
use App\Modules\AiAssistance\Domain\Exceptions\AiProviderError;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\ScriptedAiAgentGateway;
use Tests\TestCase;
use Throwable;

class TemplateAssistantTest extends TestCase
{
    use CreatesSyllabusProcess, RefreshDatabase;

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

    public function test_proposes_a_teacher_field_that_the_import_accepts_without_touching_the_template(): void
    {
        $gateway = $this->script([
            ScriptedAiAgentGateway::call('ver_plantilla'),
            ScriptedAiAgentGateway::call('agregar_campo', ['seccion' => 'resultados', 'nombre' => 'Competencias', 'quien_llena' => 'docente', 'contenido_html' => '<p>Describa las competencias:</p><p></p>', 'posicion' => 0]),
            ScriptedAiAgentGateway::call('validar_borrador'),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Propongo el campo Competencias.']),
        ]);
        $before = TemplateDraft::fingerprint($this->template);

        $outcome = $this->run_('Agrega Competencias');

        $this->assertSame(TemplateAssistantOutcome::PROPOSAL, $outcome->status);
        $this->assertSame('Propongo el campo Competencias.', $outcome->message);
        $this->assertSame(['Nuevo campo «Competencias» en «4. Resultados de aprendizaje».'], $outcome->proposal['changes'] ?? null);
        $this->assertSame($before, $outcome->proposal['fingerprint'] ?? null);
        $this->assertSame($before, TemplateDraft::fingerprint($this->template->fresh()));
        $this->assertStringContainsString('"valido":true', $gateway->toolResults(3)[2]);
        $this->assertSame(['prompt' => 400, 'completion' => 40, 'cached' => 320], $outcome->usage);

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $outcome->proposal['payload'] ?? [])->assertOk();
        $field = collect(TemplateDraft::fromTemplate($this->template->fresh())->overview())->firstWhere('clave', 'resultados')['campos'][1] ?? null;
        $this->assertSame(['posicion' => 2, 'clave' => 'competencias', 'nombre' => 'Competencias', 'quien_llena' => 'docente', 'resumen' => 'Describa las competencias:'], $field);
    }

    public function test_required_sections_cannot_be_removed_and_the_reason_goes_back_to_the_model(): void
    {
        $gateway = $this->script([
            ScriptedAiAgentGateway::call('quitar_seccion', ['clave' => 'habilidades']),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Esa sección es obligatoria.']),
        ]);

        $outcome = $this->run_('Quita las habilidades blandas');

        $this->assertSame(TemplateAssistantOutcome::ANSWER, $outcome->status);
        $this->assertNull($outcome->proposal);
        $this->assertStringContainsString('es obligatoria y no se puede quitar', $gateway->toolResults(1)[0]);
    }

    public function test_unknown_variables_and_invalid_responsibles_are_reported_to_the_model(): void
    {
        $gateway = $this->script([
            ScriptedAiAgentGateway::calls(
                ['agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'Dato', 'quien_llena' => 'institucional', 'contenido_html' => '<p><span data-variable="inventada"></span></p>', 'posicion' => 0]],
                ['cambiar_responsable_campo', ['clave' => 'descripcion', 'quien_llena' => 'decano']],
                ['ver_campo', ['clave' => 'no_existe']],
            ),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'No pude.']),
        ]);

        $this->run_('Pedido');

        [$variable, $responsible, $missing] = $gateway->toolResults(1);
        $this->assertStringContainsString('El contenido no es válido', $variable);
        $this->assertStringContainsString('quien_llena debe ser', $responsible);
        $this->assertStringContainsString('No existe el campo «no_existe»', $missing);
    }

    public function test_an_answer_over_an_invalid_draft_is_sent_back_for_correction(): void
    {
        $gateway = $this->script([
            ScriptedAiAgentGateway::call('agregar_seccion', ['titulo' => 'Prácticas', 'orientacion' => 'vertical', 'posicion' => 8]),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
            ScriptedAiAgentGateway::call('agregar_campo', ['seccion' => 'practicas', 'nombre' => '', 'quien_llena' => 'docente', 'contenido_html' => '', 'posicion' => 0]),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Agregué la sección Prácticas.']),
        ]);

        $outcome = $this->run_('Agrega prácticas');

        $this->assertStringContainsString('no tiene campos', $gateway->toolResults(2)[1]);
        $this->assertSame(TemplateAssistantOutcome::PROPOSAL, $outcome->status);
        $this->assertSame(['Nueva sección «Prácticas».', 'Nuevo campo «campo» en «Prácticas».'], $outcome->proposal['changes'] ?? null);
        $this->assertSame('practicas', $outcome->proposal['payload']['sections'][7]['key'] ?? null);
    }

    public function test_moves_and_renames_keep_the_keys_and_list_the_changes(): void
    {
        $this->script([
            ScriptedAiAgentGateway::calls(
                ['mover_seccion', ['clave' => 'bibliografia', 'posicion' => 7]],
                ['renombrar_campo', ['clave' => 'metodologia', 'nombre' => '7.1 Métodos de enseñanza y aprendizaje']],
                ['cambiar_orientacion_seccion', ['clave' => 'planificacion', 'orientacion' => 'vertical']],
            ),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
        ]);

        $outcome = $this->run_('Pedido');

        $this->assertSame([
            'La sección «6. Distribución y planificación de las unidades curriculares» pasa a vertical.',
            'Cambia el orden de las secciones.',
            'El campo «7.1 Métodos de enseñanza – aprendizaje» se llamará «7.1 Métodos de enseñanza y aprendizaje».',
        ], $outcome->proposal['changes'] ?? null);
        $keys = array_column($outcome->proposal['payload']['sections'] ?? [], 'key');
        $this->assertSame('bibliografia', $keys[6]);
        $this->assertSame(array_column(SyllabusTemplate::query()->firstOrFail()->sections->all(), 'clave'), [...array_slice($keys, 0, 6), ...array_slice($keys, 7, 4), 'bibliografia', ...array_slice($keys, 11)]);
    }

    public function test_new_fields_never_reuse_the_key_of_a_removed_field(): void
    {
        $this->script([
            ScriptedAiAgentGateway::calls(
                ['quitar_campo', ['clave' => 'herramientas_tic']],
                ['agregar_campo', ['seccion' => 'metodologia', 'nombre' => 'Herramientas TIC', 'quien_llena' => 'docente', 'contenido_html' => '<p></p>', 'posicion' => 4]],
            ),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
        ]);

        $outcome = $this->run_('Pedido');

        $fields = array_column($outcome->proposal['payload']['sections'][6]['fields'] ?? [], 'key');
        $this->assertSame(['metodologia', 'tecnicas_ensenanza', 'recursos_medios', 'herramientas_tic_2', 'ambientes_aprendizaje'], $fields);
    }

    public function test_presets_set_the_content_and_who_fills_it(): void
    {
        $this->script([
            ScriptedAiAgentGateway::call('usar_preset', ['clave' => 'descripcion', 'preset' => 'bibliografia_basica']),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
        ]);

        $outcome = $this->run_('Pedido');

        $field = $outcome->proposal['payload']['sections'][1]['fields'][0] ?? [];
        $this->assertSame('docente', $field['quien_llena'] ?? null);
        $this->assertSame('table', $field['content']['content'][0]['type'] ?? null);
    }

    public function test_stops_at_the_step_limit(): void
    {
        config(['ai.template.max_steps' => 3]);
        $this->script(array_fill(0, 3, ScriptedAiAgentGateway::call('ver_plantilla')));

        $outcome = $this->run_('Pedido');

        $this->assertSame(TemplateAssistantOutcome::FAILED, $outcome->status);
        $this->assertSame(TemplateAssistant::STEP_LIMIT, $outcome->errorCode);
        $this->assertSame('No pude completar el pedido. Intente explicarlo de otra forma.', $outcome->message);
    }

    public function test_stops_after_too_many_errors_in_a_row(): void
    {
        config(['ai.template.max_consecutive_errors' => 2]);
        $this->script(array_fill(0, 3, ScriptedAiAgentGateway::call('ver_campo', ['clave' => 'nada'])));

        $outcome = $this->run_('Pedido');

        $this->assertSame(TemplateAssistant::TOO_MANY_ERRORS, $outcome->errorCode);
        $this->assertSame(3, $outcome->steps);
    }

    public function test_stops_when_the_time_runs_out(): void
    {
        $this->script([
            ScriptedAiAgentGateway::call('ver_plantilla'),
            ScriptedAiAgentGateway::call('ver_plantilla'),
        ]);
        app()->bind(AiAgentGateway::class, fn (): AiAgentGateway => new class($this) implements AiAgentGateway
        {
            public function __construct(private readonly TemplateAssistantTest $test) {}

            public function version(): string
            {
                return 'lento';
            }

            public function step(AiAgentRequest $request): AiAgentStep
            {
                $this->test->travel(3)->minutes();

                return ScriptedAiAgentGateway::call('ver_plantilla');
            }
        });

        $outcome = $this->run_('Pedido');

        $this->assertSame(TemplateAssistant::TIME_LIMIT, $outcome->errorCode);
        $this->assertSame(1, $outcome->steps);
    }

    public function test_blank_answers_count_as_retries(): void
    {
        $this->script([
            new AiContractException('El proveedor devolvió una respuesta en blanco.'),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Hola.']),
        ]);

        $outcome = $this->run_('Hola');

        $this->assertSame(TemplateAssistantOutcome::ANSWER, $outcome->status);
        $this->assertSame('(respuesta inválida)', $outcome->trace[0]['funcion']);
    }

    public function test_provider_errors_end_with_a_simple_message(): void
    {
        $this->script([new AiProviderError(AiProviderError::NO_BALANCE, 'Sin saldo.')]);

        $outcome = $this->run_('Pedido');

        $this->assertSame(AiProviderError::NO_BALANCE, $outcome->errorCode);
        $this->assertSame('El asistente no está disponible por ahora. Puede seguir editando la plantilla.', $outcome->message);
    }

    public function test_an_open_convocation_only_allows_reading(): void
    {
        $this->openSyllabusProcess($this->template->id);
        $gateway = $this->script([
            ScriptedAiAgentGateway::call('agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'X', 'quien_llena' => 'docente', 'contenido_html' => '', 'posicion' => 0]),
            ScriptedAiAgentGateway::call('ver_estado'),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'La convocatoria está abierta.']),
        ]);

        $outcome = $this->run_('Agrega un campo');

        $names = array_map(fn ($tool): string => $tool->name, $gateway->requests[0]->tools);
        $this->assertNotContains('agregar_campo', $names);
        $this->assertContains('ver_plantilla', $names);
        $this->assertStringContainsString('La convocatoria está abierta', (string) $gateway->requests[0]->messages[0]['content']);
        $this->assertStringContainsString('no se pueden proponer cambios', $gateway->toolResults(1)[0]);
        $this->assertStringContainsString('"estado":"abierta"', $gateway->toolResults(2)[1]);
        $this->assertSame(TemplateAssistantOutcome::ANSWER, $outcome->status);
    }

    public function test_a_question_ends_the_turn_without_a_proposal(): void
    {
        $this->script([
            ScriptedAiAgentGateway::call('agregar_seccion', ['titulo' => 'Datos', 'orientacion' => 'vertical', 'posicion' => 0]),
            ScriptedAiAgentGateway::call('preguntar', ['pregunta' => '¿Qué datos del docente quiere?']),
        ]);

        $outcome = $this->run_('Agrega los datos del docente');

        $this->assertSame(TemplateAssistantOutcome::QUESTION, $outcome->status);
        $this->assertSame('¿Qué datos del docente quiere?', $outcome->message);
        $this->assertNull($outcome->proposal);
    }

    public function test_every_tool_schema_fits_the_strict_mode(): void
    {
        $tools = new TemplateTools(TemplateDraft::fromTemplate($this->template), app(TemplateDraftValidator::class), ['estado' => 'ninguna', 'aviso' => '']);

        foreach ($tools->specs() as $spec) {
            $properties = (array) $spec->parameters['properties'];
            $this->assertSame('object', $spec->parameters['type'], $spec->name);
            $this->assertFalse($spec->parameters['additionalProperties'], $spec->name);
            $this->assertSame(array_keys($properties), $spec->parameters['required'], $spec->name);
            $this->assertMatchesRegularExpression('/^[a-z_]{1,64}$/', $spec->name);
            foreach ($properties as $property) {
                $this->assertSame([], array_intersect(array_keys($property), ['minLength', 'maxLength', 'minItems', 'maxItems']), $spec->name);
            }
        }
        $this->assertJson(json_encode($tools->specs()[0]->parameters, JSON_THROW_ON_ERROR));
        $this->assertSame('{"type":"object","properties":{},"required":[],"additionalProperties":false}', json_encode($tools->specs()[0]->parameters));
    }

    public function test_the_user_tag_is_stable_and_anonymous(): void
    {
        $tag = TemplateAssistant::userTag('11111111-1111-1111-1111-111111111111');

        $this->assertSame($tag, TemplateAssistant::userTag('11111111-1111-1111-1111-111111111111'));
        $this->assertNotSame($tag, TemplateAssistant::userTag('22222222-2222-2222-2222-222222222222'));
        $this->assertMatchesRegularExpression('/^adm-[0-9a-f]{32}$/', $tag);
    }

    public function test_the_trial_command_runs_with_the_simulator(): void
    {
        config(['ai.driver' => 'baseline']);

        $this->artisan('template:assistant-trial', ['--pedido' => ['¿Qué campos llena Coordinación?']])
            ->expectsOutputToContain('respuesta en 2 pasos')
            ->assertSuccessful();
        $this->assertSame(TemplateDraft::fingerprint($this->template), TemplateDraft::fingerprint($this->template->fresh()));
    }

    /**
     * @param  list<AiAgentStep|Throwable>  $script
     */
    private function script(array $script): ScriptedAiAgentGateway
    {
        $gateway = new ScriptedAiAgentGateway($script);
        app()->instance(AiAgentGateway::class, $gateway);

        return $gateway;
    }

    private function run_(string $request): TemplateAssistantOutcome
    {
        return app(TemplateAssistant::class)->run($this->template->fresh(), $request, [], 'adm-prueba');
    }
}
