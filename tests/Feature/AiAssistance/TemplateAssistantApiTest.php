<?php

namespace Tests\Feature\AiAssistance;

use App\Models\User;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistant;
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

    public function test_a_request_over_the_editor_draft_marks_only_the_assistant_changes_and_goes_to_the_editor(): void
    {
        $this->script([...$this->addField('Competencias'), ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Propongo **Competencias**.'])]);
        $draft = $this->draft();
        $draft[1]['fields'][] = ['key' => 'sin_guardar', 'label' => 'Sin guardar', 'quien_llena' => 'carrera', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]];

        $this->ask('Agrega Competencias', null, $draft, 'sesion-1:7')->assertAccepted();

        $chat = $this->getJson(route('api.v1.admin.template.assistant.show'))->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.content', 'Agrega Competencias')
            ->assertJsonPath('messages.1.result', 'propuesta')
            ->assertJsonPath('messages.1.content', 'Propongo **Competencias**.')
            ->assertJsonPath('messages.1.proposal.status', 'pendiente')
            ->assertJsonPath('messages.1.proposal.draft_version', 'sesion-1:7')
            ->assertJsonPath('messages.1.proposal.changes', ['Nuevo campo «Competencias» en «4. Resultados de aprendizaje».']);
        $proposalId = (string) $chat->json('messages.1.proposal.id');

        $this->getJson(route('api.v1.admin.template.assistant.proposals.show', $proposalId))->assertOk()
            ->assertJsonPath('draft_version', 'sesion-1:7')
            ->assertJsonPath('changed', ['sections' => [], 'fields' => ['competencias']])
            ->assertJsonPath('sections.1.fields.1.key', 'sin_guardar')
            ->assertJsonPath('sections.3.fields.1.key', 'competencias');

        $this->postJson(route('api.v1.admin.template.assistant.proposals.apply', $proposalId))->assertOk();

        $this->assertSame(TemplateAssistantProposal::APPLIED, TemplateAssistantProposal::query()->findOrFail($proposalId)->estado);
        $this->assertNull(collect($this->fields())->firstWhere('clave', 'competencias'));
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.propuesta_ia_aplicada', 'tipo_recurso' => 'propuesta_ia_plantilla']);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'ia.plantilla_solicitada']);
        $this->assertDatabaseMissing('eventos_auditoria', ['accion' => 'plantilla.importada']);
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
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.propuesta_ia_descartada']);
        $this->postJson(route('api.v1.admin.template.assistant.proposals.apply', $proposal->id))->assertUnprocessable();
    }

    public function test_problems_already_in_the_draft_do_not_block_the_proposal(): void
    {
        $gateway = $this->script([...$this->addField('Competencias'), ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Listo.'])]);
        $draft = $this->draft();
        $draft[] = ['key' => 'a_medias', 'title' => 'A medias', 'orientation' => 'portrait', 'fields' => []];

        $this->ask('Agrega Competencias', null, $draft);

        $messages = $gateway->requests[0]->messages;
        $opening = (string) ($messages[array_key_last($messages)]['content'] ?? '');
        $this->assertStringContainsString('El borrador ya tenía estos problemas', $opening);
        $this->assertStringContainsString('«A medias» no tiene campos', $opening);
        $this->assertSame(['Nuevo campo «Competencias» en «4. Resultados de aprendizaje».'], TemplateAssistantProposal::query()->firstOrFail()->cambios['lista']);
    }

    public function test_an_unreadable_draft_is_rejected(): void
    {
        $draft = $this->draft();
        $draft[0]['fields'][0]['content'] = ['type' => 'nada'];

        $this->ask('Hola', null, $draft)->assertUnprocessable()->assertJsonValidationErrors('draft.0.fields.0.content');
        $this->assertSame(0, TemplateAssistantMessage::query()->count());
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

    public function test_a_new_proposal_replaces_the_pending_one_and_starts_from_the_draft(): void
    {
        $gateway = $this->script([
            ...$this->addField('Competencias'),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Primera.']),
            ScriptedAiAgentGateway::call('cambiar_orientacion_seccion', ['clave' => 'resultados', 'orientacion' => 'horizontal']),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Segunda.']),
        ]);
        $this->ask('Agrega Competencias');

        $this->ask('Ponla en horizontal');

        $messages = $gateway->requests[2]->messages;
        $this->assertStringContainsString('Las propuestas anteriores que no aplicó no están en el borrador', (string) ($messages[array_key_last($messages)]['content'] ?? ''));
        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        $proposals = TemplateAssistantProposal::query()->orderBy('creada_en')->get();
        $this->assertSame([TemplateAssistantProposal::REPLACED, TemplateAssistantProposal::PENDING], $proposals->pluck('estado')->all());
        $this->assertSame(['La sección «4. Resultados de aprendizaje» pasa a horizontal.'], $proposals->last()?->cambios['lista']);
    }

    public function test_approving_a_pending_proposal_asks_to_apply_it_instead_of_repeating_it(): void
    {
        $gateway = $this->script([
            ...$this->addField('Competencias'),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Propongo Competencias.']),
            ...$this->addField('Competencias'),
            ScriptedAiAgentGateway::call('responder', ['mensaje' => 'Propuesta lista otra vez.']),
        ]);
        $this->ask('Agrega Competencias');

        $this->ask('si eso');

        $messages = $gateway->requests[2]->messages;
        $this->assertStringContainsString('Su última propuesta sigue pendiente', (string) ($messages[array_key_last($messages)]['content'] ?? ''));
        $this->assertSame([TemplateAssistantProposal::PENDING], TemplateAssistantProposal::query()->pluck('estado_propuesta')->all());
        $this->getJson(route('api.v1.admin.template.assistant.show'))
            ->assertJsonPath('messages.3.result', 'respuesta')
            ->assertJsonPath('messages.3.content', TemplateAssistant::SAME_AS_PENDING)
            ->assertJsonPath('messages.3.proposal', null)
            ->assertJsonPath('messages.1.proposal.status', 'pendiente');
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
            ScriptedAiAgentGateway::call('agregar_campo', ['seccion' => 'resultados', 'nombre' => $name, 'quien_llena' => 'carrera', 'contenido_html' => '<p></p>', 'despues_de' => '(final)']),
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

    /**
     * @param  list<array<string, mixed>>|null  $draft
     */
    private function ask(string $message, ?string $key = null, ?array $draft = null, string $version = 'sesion-1:1'): TestResponse
    {
        return $this->postJson(route('api.v1.admin.template.assistant.messages.store'), [
            'message' => $message,
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'draft' => $draft ?? $this->draft(),
            'draft_version' => $version,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function draft(): array
    {
        return TemplateDraft::fromTemplate(SyllabusTemplate::query()->firstOrFail())->payload()['sections'];
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
