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
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
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

    public function test_the_assistant_check_says_the_same_as_the_live_check_of_the_editor(): void
    {
        $sections = TemplateDraft::fromTemplate($this->template)->payload()['sections'];
        $sections[1]['fields'][0]['key'] = $sections[0]['fields'][0]['key'];
        $sections[2]['title'] = '';
        $sections[] = ['key' => 'vacia', 'title' => 'Vacía', 'orientation' => 'portrait', 'fields' => []];
        $draft = TemplateDraft::fromDraft($this->template, $sections);

        $live = $this->postJson(route('api.v1.admin.template.draft.check', $this->template), $draft->payload())->assertOk()->json('problems');
        $assistant = app(TemplateDraftValidator::class)->errors($draft);

        $this->assertNotEmpty($live);
        $this->assertCount(count(array_unique(array_column($live, 'message'))), $assistant);
        foreach ($live as $problem) {
            $this->assertNotEmpty(array_filter($assistant, fn (string $error): bool => str_ends_with($error, $problem['message'])), $problem['message']);
        }
    }

    public function test_proposes_a_teacher_field_that_the_import_accepts_without_touching_the_template(): void
    {
        $gateway = $this->script([
            ScriptedAiAgentGateway::call('ver_plantilla'),
            ScriptedAiAgentGateway::call('agregar_campo', ['seccion' => 'resultados', 'nombre' => 'Competencias', 'quien_llena' => 'docente', 'contenido_html' => '<p>Describa las competencias:</p><p></p>', 'despues_de' => '(final)']),
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
        $this->assertSame(['posicion' => 2, 'clave' => 'competencias', 'nombre' => 'Competencias', 'quien_llena' => 'carrera', 'resumen' => 'Describa las competencias:'], $field);
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
                ['agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'Dato', 'quien_llena' => 'institucional', 'contenido_html' => '<p><span data-variable="inventada"></span></p>', 'despues_de' => '(final)']],
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

    public function test_the_assistant_places_responses_with_their_settings(): void
    {
        $response = '<p>Modalidad: <span data-response=\'{"kind":"una","options":[{"const":"o1","title":"Presencial"},{"const":"o2","title":"En línea"}],"display":"desplegable","help":"Elija la modalidad principal."}\'></span></p>'
            .'<p>Observaciones: <span data-response=\'{"kind":"texto","required":false,"maxLength":300}\'></span></p>';
        $gateway = $this->script([
            ScriptedAiAgentGateway::calls(
                ['agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'Mal', 'quien_llena' => 'carrera', 'contenido_html' => '<p><span data-response=\'{"kind":"texto","maxLength":5000}\'></span></p>', 'despues_de' => '(final)']],
                ['agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'Modalidad', 'quien_llena' => 'carrera', 'contenido_html' => $response, 'despues_de' => '(final)']],
            ),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
        ]);

        $outcome = $this->run_('Pedido');

        [$invalid, $valid] = $gateway->toolResults(1);
        $this->assertStringContainsString('El límite de caracteres va de 1 a 3000.', $invalid);
        $this->assertStringContainsString('"ok":true', $valid);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $outcome->proposal['payload'] ?? [])->assertOk();
        $content = FieldDefinition::query()->where('clave', 'modalidad')->firstOrFail()->contenido;
        $this->assertEquals(['kind' => 'una', 'options' => [['const' => 'o1', 'title' => 'Presencial'], ['const' => 'o2', 'title' => 'En línea']], 'value' => null, 'help' => 'Elija la modalidad principal.', 'display' => 'desplegable'], $content['content'][0]['content'][1]['attrs']);
        $this->assertEquals(['kind' => 'texto', 'options' => [], 'value' => '', 'required' => false, 'maxLength' => 300], $content['content'][1]['content'][1]['attrs']);
    }

    public function test_the_assistant_shows_a_field_or_a_response_only_if_an_earlier_answer_was_chosen(): void
    {
        $question = '<p>¿Usa laboratorio? <span data-response=\'{"kind":"una","options":[{"const":"o1","title":"Sí"},{"const":"o2","title":"No"}],"id":"usa_laboratorio"}\'></span></p>'
            .'<p>¿Cuál? <span data-response=\'{"kind":"texto","showIf":{"response":"usa_laboratorio","options":["o1"]}}\'></span></p>';
        $gateway = $this->script([
            ScriptedAiAgentGateway::call('agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'Laboratorio', 'quien_llena' => 'carrera', 'contenido_html' => $question, 'despues_de' => '(final)']),
            ScriptedAiAgentGateway::calls(
                ['mostrar_solo_si', ['clave' => 'descripcion', 'respuesta' => 'usa_laboratorio', 'opciones' => ['Sí']]],
                ['mostrar_solo_si', ['clave' => 'objetivo_general', 'respuesta' => 'usa_laboratorio', 'opciones' => ['Tal vez']]],
                ['mostrar_solo_si', ['clave' => 'objetivo_general', 'respuesta' => 'no_existe', 'opciones' => ['Sí']]],
                ['mostrar_solo_si', ['clave' => 'objetivo_general', 'respuesta' => 'usa_laboratorio', 'opciones' => ['Sí']]],
            ),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
        ]);

        $outcome = $this->run_('Muestra el objetivo general solo si usa laboratorio');

        [$earlier, $missingOption, $missingResponse, $valid] = array_slice($gateway->toolResults(2), 1);
        $this->assertStringContainsString('usa una respuesta que ya no está antes de esta parte', $earlier);
        $this->assertStringContainsString('no tiene la opción «Tal vez»', $missingOption);
        $this->assertStringContainsString('No hay ninguna respuesta con el identificador «no_existe»', $missingResponse);
        $this->assertStringContainsString('"ok":true', $valid);
        $this->assertContains('«Objetivo general» se mostrará solo si la respuesta «usa_laboratorio» tiene: o1.', $outcome->proposal['changes'] ?? []);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $outcome->proposal['payload'] ?? [])->assertOk();
        $this->assertSame(['objetivo_general' => ['response' => 'usa_laboratorio', 'options' => ['o1']]], $this->template->fresh()->definition()->fieldConditions());
        $content = FieldDefinition::query()->where('clave', 'laboratorio')->firstOrFail()->contenido;
        $this->assertEquals(['response' => 'usa_laboratorio', 'options' => ['o1']], $content['content'][1]['content'][1]['attrs']['showIf']);
    }

    public function test_the_assistant_creates_a_simple_repeatable_table_and_places_it_in_a_field(): void
    {
        $column = fn (string $name, string $type, bool $required = false, array $options = []): array => ['nombre' => $name, 'tipo' => $type, 'obligatoria' => $required, 'opciones' => $options];
        $table = ['titulo' => 'Prácticas de laboratorio', 'fila' => 'práctica', 'minimo' => 1, 'maximo' => 10, 'al_empezar' => 2];
        $gateway = $this->script([
            ScriptedAiAgentGateway::calls(
                ['crear_tabla_repetible', [...$table, 'columnas' => [$column('Modalidad', 'opcion')]]],
                ['crear_tabla_repetible', [...$table, 'maximo' => 0, 'columnas' => [$column('Tema', 'texto')]]],
                ['crear_tabla_repetible', [...$table, 'columnas' => [$column('Tema', 'texto', true), $column('Modalidad', 'opcion', false, ['Presencial', 'Virtual']), $column('Horas', 'numero')]]],
            ),
            ScriptedAiAgentGateway::call('agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'Prácticas', 'quien_llena' => 'carrera', 'contenido_html' => '<div data-block="tabla_practicas_de_laboratorio"></div>', 'despues_de' => '(final)']),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
        ]);

        $outcome = $this->run_('Agrega una tabla de prácticas');

        [$noOptions, $badLimits, $created] = $gateway->toolResults(1);
        $this->assertStringContainsString('La columna «Modalidad» es de tipo opcion: escriba sus opciones sin repetir.', $noOptions);
        $this->assertStringContainsString('La tabla no es válida', $badLimits);
        $this->assertStringContainsString('"clave":"tabla_practicas_de_laboratorio"', $created);
        $this->assertStringContainsString('"ok":true', $gateway->toolResults(2)[3]);
        $this->assertContains('Nueva tabla repetible «Prácticas de laboratorio».', $outcome->proposal['changes'] ?? []);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $outcome->proposal['payload'] ?? [])->assertOk();
        $block = SyllabusTemplate::query()->firstOrFail()->definition()->block('tabla_practicas_de_laboratorio');
        $this->assertSame(['tema', 'modalidad', 'horas'], array_keys($block['value']['filas']['items']['properties']));
        $this->assertSame(['tema_obligatoria'], array_column($block['rules'], 'code'));
        $this->assertSame('tabla_practicas_de_laboratorio', FieldDefinition::query()->where('clave', 'practicas')->firstOrFail()->contenido['content'][0]['attrs']['kind']);
    }

    public function test_only_the_palette_colors_are_accepted(): void
    {
        $gateway = $this->script([
            ScriptedAiAgentGateway::calls(
                ['agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'Verde', 'quien_llena' => 'docente', 'contenido_html' => '<table><tr><th style="background:#00FF00">A</th></tr><tr><td></td></tr></table>', 'despues_de' => '(final)']],
                ['agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'Rojo', 'quien_llena' => 'docente', 'contenido_html' => '<p><span style="color:#C00000">Atención</span></p>', 'despues_de' => '(final)']],
                ['agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'Oficial', 'quien_llena' => 'docente', 'contenido_html' => '<table><tr><th style="background:#4f81bd;color:#FFFFFF;font-weight:bold"><span style="color:#365F91">T</span></th></tr><tr><td></td></tr></table>', 'despues_de' => '(final)']],
            ),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
        ]);

        $outcome = $this->run_('Pedido');

        [$green, $red, $official] = $gateway->toolResults(1);
        $this->assertStringContainsString('Colores no permitidos: #00FF00', $green);
        $this->assertStringContainsString('Colores no permitidos: #C00000', $red);
        $this->assertStringContainsString('"ok":true', $official);
        $this->assertSame(['Nuevo campo «Oficial» en «2. Descripción de la asignatura».'], $outcome->proposal['changes'] ?? null);
    }

    public function test_an_answer_over_an_invalid_draft_is_sent_back_for_correction(): void
    {
        $gateway = $this->script([
            ScriptedAiAgentGateway::call('agregar_seccion', ['titulo' => 'Prácticas', 'orientacion' => 'vertical', 'despues_de' => 'metodologia']),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
            ScriptedAiAgentGateway::call('agregar_campo', ['seccion' => 'practicas', 'nombre' => '', 'quien_llena' => 'docente', 'contenido_html' => '', 'despues_de' => '(final)']),
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
                ['mover_seccion', ['clave' => 'bibliografia', 'despues_de' => 'planificacion']],
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

    public function test_sections_are_placed_after_a_key_and_renumbered_with_their_fields(): void
    {
        $gateway = $this->script([
            ScriptedAiAgentGateway::calls(
                ['mover_seccion', ['clave' => 'habilidades', 'despues_de' => 'perfil_egreso']],
                ['agregar_seccion', ['titulo' => '1. Proyecto integrador', 'orientacion' => 'vertical', 'despues_de' => 'planificacion']],
                ['mover_seccion', ['clave' => 'etica', 'despues_de' => 'nada']],
            ),
            ScriptedAiAgentGateway::calls(
                ['agregar_campo', ['seccion' => 'proyecto_integrador', 'nombre' => 'Proyecto', 'quien_llena' => 'docente', 'contenido_html' => '', 'despues_de' => '(inicio)']],
                ['renumerar'],
            ),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']),
        ]);

        $outcome = $this->run_('Pedido');

        $this->assertStringContainsString('«nada»', $gateway->toolResults(1)[2]);
        $this->assertStringContainsString('«1. Proyecto integrador» → «6. Proyecto integrador»', $gateway->toolResults(2)[4] ?? '');
        $titles = array_column($outcome->proposal['payload']['sections'] ?? [], 'title');
        $this->assertSame([
            '1. Identificación institucional', '2. Descripción de la asignatura', '3. Objetivo(s) de la asignatura', '4. Resultados de aprendizaje',
            '5. Distribución y planificación de las unidades curriculares', '6. Proyecto integrador', '7. Metodología y ambientes de aprendizaje',
            '8. Evaluación de los aprendizajes', '9. Relación de la asignatura con los resultados de aprendizaje del perfil de egreso de la carrera',
            '10. Habilidades blandas de la asignatura', '11. Conducta y comportamiento ético', '12. Bibliografía', 'Revisión y aprobación',
        ], $titles);
        $this->assertSame(['7.1', '7.2', '7.3', '7.4', '7.5'], array_map(fn (array $field): string => explode(' ', $field['label'])[0], $outcome->proposal['payload']['sections'][6]['fields'] ?? []));
    }

    public function test_new_fields_never_reuse_the_key_of_a_removed_field(): void
    {
        $this->script([
            ScriptedAiAgentGateway::calls(
                ['quitar_campo', ['clave' => 'herramientas_tic']],
                ['agregar_campo', ['seccion' => 'metodologia', 'nombre' => 'Herramientas TIC', 'quien_llena' => 'docente', 'contenido_html' => '<p></p>', 'despues_de' => 'recursos_medios']],
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
        $this->assertSame('carrera', $field['quien_llena'] ?? null);
        $this->assertSame(['documentBlock', 'bibliografia'], [$field['content']['content'][0]['type'] ?? null, $field['content']['content'][0]['attrs']['kind'] ?? null]);
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
            ScriptedAiAgentGateway::call('agregar_campo', ['seccion' => 'descripcion', 'nombre' => 'X', 'quien_llena' => 'docente', 'contenido_html' => '', 'despues_de' => '(final)']),
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

    public function test_a_short_reply_is_presented_as_the_answer_to_the_previous_question(): void
    {
        $gateway = $this->script([ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.']), ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Hola.'])]);
        $history = [['role' => 'user', 'content' => 'cambia el 8'], ['role' => 'assistant', 'content' => '¿1) el título o 2) el campo?']];

        app(TemplateAssistant::class)->run($this->template->fresh(), '1', $history, 'adm-prueba');
        app(TemplateAssistant::class)->run($this->template->fresh(), 'Hola', [], 'adm-prueba');

        $last = fn (int $request): string => (string) ($gateway->requests[$request]->messages[array_key_last($gateway->requests[$request]->messages)]['content'] ?? '');
        $this->assertSame(['user', 'assistant', 'user'], array_column($gateway->requests[0]->messages, 'role'));
        $this->assertStringContainsString('puede ser la respuesta a su última pregunta', $last(0));
        $this->assertStringContainsString("Pedido del administrador:\nHola", $last(1));
    }

    public function test_a_question_ends_the_turn_without_a_proposal(): void
    {
        $this->script([
            ScriptedAiAgentGateway::call('agregar_seccion', ['titulo' => 'Datos', 'orientacion' => 'vertical', 'despues_de' => '(final)']),
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
