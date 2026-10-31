<?php

namespace Tests\Feature\AiAssistance;

use App\Models\User;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateDraft;
use App\Modules\AiAssistance\Domain\Contracts\AiAgentGateway;
use App\Modules\AiAssistance\Domain\Data\AiAgentStep;
use App\Modules\AiAssistance\Domain\Exceptions\AiProviderError;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\TemplateAssistantMessage;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\TemplateAssistantProposal;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\ScriptedAiAgentGateway;
use Tests\TestCase;
use Throwable;

class TemplateAssistantApiTest extends TestCase
{
    use CreatesSyllabusProcess, RefreshDatabase;

    private SyllabusTemplate $template;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['ai.driver' => 'baseline']);
        $this->admin = $this->actingAsRole('admin@silabos.test');
        $this->postJson(route('api.v1.admin.template.store'), ['official' => true])->assertCreated();
        $this->template = SyllabusTemplate::query()->firstOrFail();
    }

    public function test_a_request_becomes_a_proposal_that_the_administrator_applies(): void
    {
        $this->script([...$this->addField('Competencias'), ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Propongo **Competencias**.'])]);

        $this->ask('Agrega Competencias')->assertAccepted();

        $chat = $this->getJson(route('api.v1.admin.template.assistant.show'))->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('locked', false)
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.role', 'usuario')
            ->assertJsonPath('messages.0.content', 'Agrega Competencias')
            ->assertJsonPath('messages.1.status', 'completado')
            ->assertJsonPath('messages.1.result', 'propuesta')
            ->assertJsonPath('messages.1.content', 'Propongo **Competencias**.')
            ->assertJsonPath('messages.1.proposal.status', 'pendiente')
            ->assertJsonPath('messages.1.proposal.changes', ['Nuevo campo «Competencias» en «4. Resultados de aprendizaje».']);
        $proposalId = (string) $chat->json('messages.1.proposal.id');

        $this->getJson(route('api.v1.admin.template.assistant.proposals.show', $proposalId))->assertOk()
            ->assertJsonPath('changed', ['sections' => [], 'fields' => ['competencias']])
            ->assertJsonPath('sections.3.fields.1.key', 'competencias');
        $this->assertNull(collect($this->fields())->firstWhere('clave', 'competencias'));

        $this->postJson(route('api.v1.admin.template.assistant.proposals.apply', $proposalId))->assertOk();

        $this->assertSame('docente', collect($this->fields())->firstWhere('clave', 'competencias')['quien_llena'] ?? null);
        $this->assertSame(TemplateAssistantProposal::APPLIED, TemplateAssistantProposal::query()->findOrFail($proposalId)->estado);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.propuesta_ia_aplicada', 'tipo_recurso' => 'propuesta_ia_plantilla']);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.importada']);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'ia.plantilla_solicitada']);
        $this->postJson(route('api.v1.admin.template.assistant.proposals.apply', $proposalId))->assertUnprocessable()
            ->assertJsonValidationErrors('proposal');
    }

    public function test_a_discarded_proposal_cannot_be_applied(): void
    {
        $this->script([...$this->addField('Competencias'), ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.'])]);
        $this->ask('Agrega Competencias');
        $proposal = TemplateAssistantProposal::query()->firstOrFail();

        $this->postJson(route('api.v1.admin.template.assistant.proposals.discard', $proposal->id))->assertOk();

        $this->assertSame(TemplateAssistantProposal::DISCARDED, $proposal->fresh()?->estado);
        $this->postJson(route('api.v1.admin.template.assistant.proposals.apply', $proposal->id))->assertUnprocessable();
        $this->assertNull(collect($this->fields())->firstWhere('clave', 'competencias'));
    }

    public function test_a_proposal_goes_stale_when_the_template_changes_before_applying(): void
    {
        $this->script([...$this->addField('Competencias'), ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.'])]);
        $this->ask('Agrega Competencias');
        $proposal = TemplateAssistantProposal::query()->firstOrFail();
        $payload = TemplateDraft::fromTemplate($this->template)->payload();
        $payload['sections'][1]['title'] = '2. Descripción';
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $payload)->assertOk();

        $this->getJson(route('api.v1.admin.template.assistant.show'))->assertJsonPath('messages.1.proposal.status', 'vencida');
        $this->postJson(route('api.v1.admin.template.assistant.proposals.apply', $proposal->id))->assertUnprocessable()
            ->assertJsonPath('errors.proposal.0', 'La plantilla cambió desde que se armó la propuesta. Pida una nueva al asistente.');

        $this->assertSame(TemplateAssistantProposal::STALE, $proposal->fresh()?->estado);
        $this->assertNull(collect($this->fields())->firstWhere('clave', 'competencias'));
    }

    public function test_an_open_convocation_blocks_applying(): void
    {
        $this->script([...$this->addField('Competencias'), ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.'])]);
        $this->ask('Agrega Competencias');
        $this->openSyllabusProcess($this->template->id);

        $this->getJson(route('api.v1.admin.template.assistant.show'))->assertJsonPath('locked', true);
        $this->postJson(route('api.v1.admin.template.assistant.proposals.apply', TemplateAssistantProposal::query()->firstOrFail()->id))
            ->assertUnprocessable()->assertJsonValidationErrors('process');
    }

    public function test_only_one_request_at_a_time_and_repeated_keys_are_ignored(): void
    {
        $this->script([ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Hola.'])]);
        $key = (string) Str::uuid();

        $first = $this->ask('Hola', $key)->assertAccepted()->json('assistant_message_id');
        $this->ask('Hola', $key)->assertAccepted()->assertJsonPath('assistant_message_id', $first);
        $this->assertSame(2, TemplateAssistantMessage::query()->count());

        TemplateAssistantMessage::query()->findOrFail($first)->amend(['estado' => 'pendiente']);
        $this->ask('Otra cosa')->assertUnprocessable()->assertJsonPath('errors.message.0', 'Espere a que el asistente termine la respuesta anterior.');
        $this->deleteJson(route('api.v1.admin.template.assistant.clear'))->assertUnprocessable();
    }

    public function test_a_follow_up_builds_on_the_pending_proposal_and_replaces_it(): void
    {
        $gateway = $this->script([
            ...$this->addField('Competencias'),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Primera.']),
            ScriptedAiAgentGateway::call('cambiar_orientacion_seccion', ['clave' => 'resultados', 'orientacion' => 'horizontal']),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Segunda.']),
        ]);
        $this->ask('Agrega Competencias');

        $this->ask('Ponla también en horizontal');

        $messages = $gateway->requests[2]->messages;
        $opening = (string) ($messages[array_key_last($messages)]['content'] ?? '');
        $this->assertStringContainsString('El borrador ya incluye la propuesta anterior', $opening);
        $this->assertStringContainsString('Nuevo campo «Competencias»', $opening);
        $this->assertSame(['user', 'assistant', 'user'], array_column($gateway->requests[2]->messages, 'role'));
        $proposals = TemplateAssistantProposal::query()->orderBy('creada_en')->get();
        $this->assertSame([TemplateAssistantProposal::REPLACED, TemplateAssistantProposal::PENDING], $proposals->pluck('estado')->all());
        $this->assertSame([
            'La sección «4. Resultados de aprendizaje» pasa a horizontal.',
            'Nuevo campo «Competencias» en «4. Resultados de aprendizaje».',
        ], $proposals->last()?->cambios['lista']);
    }

    public function test_a_new_chat_hides_the_previous_messages_from_the_screen_and_the_model(): void
    {
        $gateway = $this->script([
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Uno.']),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Dos.']),
        ]);
        $this->ask('Primero');

        $this->deleteJson(route('api.v1.admin.template.assistant.clear'))->assertOk();
        $this->getJson(route('api.v1.admin.template.assistant.show'))->assertJsonCount(0, 'messages');
        $this->ask('Segundo');

        $this->assertCount(1, $gateway->requests[1]->messages);
    }

    public function test_provider_failures_leave_a_simple_message(): void
    {
        $this->script([new AiProviderError(AiProviderError::NO_BALANCE, 'Sin saldo.')]);

        $this->ask('Hola');

        $this->getJson(route('api.v1.admin.template.assistant.show'))
            ->assertJsonPath('messages.1.status', 'fallido')
            ->assertJsonPath('messages.1.error', 'El asistente no está disponible por ahora. Puede seguir editando la plantilla.')
            ->assertJsonPath('messages.1.proposal', null);
    }

    public function test_requests_are_rejected_when_the_assistant_is_not_configured(): void
    {
        config(['ai.driver' => 'disabled']);

        $this->getJson(route('api.v1.admin.template.assistant.show'))->assertJsonPath('available', false);
        $this->ask('Hola')->assertUnprocessable()->assertJsonValidationErrors('message');
    }

    public function test_only_the_administrator_reaches_the_assistant(): void
    {
        $this->script([...$this->addField('Competencias'), ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.'])]);
        $this->ask('Agrega Competencias');
        $proposal = TemplateAssistantProposal::query()->firstOrFail();

        $this->actingAsRole('coordinador@silabos.test');

        $this->getJson(route('api.v1.admin.template.assistant.show'))->assertForbidden();
        $this->ask('Hola')->assertForbidden();
        $this->postJson(route('api.v1.admin.template.assistant.proposals.apply', $proposal->id))->assertForbidden();
    }

    /**
     * @return list<AiAgentStep>
     */
    private function addField(string $name): array
    {
        return [
            ScriptedAiAgentGateway::call('agregar_campo', ['seccion' => 'resultados', 'nombre' => $name, 'quien_llena' => 'docente', 'contenido_html' => '<p></p>', 'posicion' => 0]),
        ];
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

    private function ask(string $message, ?string $key = null): TestResponse
    {
        return $this->postJson(route('api.v1.admin.template.assistant.messages.store'), ['message' => $message, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return collect(TemplateDraft::fromTemplate(SyllabusTemplate::query()->firstOrFail())->overview())->flatMap(fn (array $section): array => $section['campos'])->values()->all();
    }

    private function actingAsRole(string $email): User
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);

        return $user;
    }
}
