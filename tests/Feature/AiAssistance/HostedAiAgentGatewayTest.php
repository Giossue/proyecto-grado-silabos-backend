<?php

namespace Tests\Feature\AiAssistance;

use App\Modules\AiAssistance\Domain\Contracts\AiAgentGateway;
use App\Modules\AiAssistance\Domain\Data\AiAgentRequest;
use App\Modules\AiAssistance\Domain\Data\AiToolCall;
use App\Modules\AiAssistance\Domain\Data\AiToolSpec;
use App\Modules\AiAssistance\Domain\Exceptions\AiContractException;
use App\Modules\AiAssistance\Domain\Exceptions\AiProviderError;
use App\Modules\AiAssistance\Infrastructure\Gateways\HostedAiAgentGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class HostedAiAgentGatewayTest extends TestCase
{
    private const BETA = 'https://api.deepseek.com/beta/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config([
            'ai.driver' => 'deepseek',
            'ai.hosted.base_url' => 'https://api.deepseek.com',
            'ai.hosted.model' => 'deepseek-flash',
            'ai.hosted.api_key' => 'secret-test-key',
            'ai.template.model' => null,
            'ai.template.strict_tools' => true,
        ]);
    }

    public function test_sends_strict_tools_to_the_beta_endpoint_with_a_required_tool_and_no_thinking(): void
    {
        Http::fake([self::BETA => Http::response($this->toolAnswer('ver_campo', '{"clave":"descripcion"}'))]);

        $step = app(AiAgentGateway::class)->step($this->request());

        $this->assertInstanceOf(HostedAiAgentGateway::class, app(AiAgentGateway::class));
        $this->assertSame('deepseek:deepseek-flash', $step->gatewayVersion);
        $this->assertSame('ver_campo', $step->toolCalls[0]->name);
        $this->assertSame(['clave' => 'descripcion'], $step->toolCalls[0]->arguments);
        $this->assertSame(['prompt' => 1200, 'completion' => 40, 'cached' => 1000], $step->usage);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === self::BETA
                && $request->hasHeader('Authorization', 'Bearer secret-test-key')
                && $data['model'] === 'deepseek-flash'
                && $data['tool_choice'] === 'required'
                && $data['thinking'] === ['type' => 'disabled']
                && $data['user_id'] === 'adm-prueba'
                && $data['messages'][0] === ['role' => 'system', 'content' => 'Reglas']
                && data_get($data, 'tools.0.type') === 'function'
                && data_get($data, 'tools.0.function.name') === 'ver_campo'
                && data_get($data, 'tools.0.function.strict') === true
                && data_get($data, 'tools.0.function.parameters.additionalProperties') === false;
        });
    }

    public function test_sends_the_configured_temperature_without_reasoning(): void
    {
        config(['ai.template.temperature' => '0.3']);
        Http::fake([self::BETA => Http::response($this->toolAnswer('ver_plantilla', '{}'))]);

        app(AiAgentGateway::class)->step($this->request());

        Http::assertSent(fn (Request $request): bool => $request->data()['temperature'] === 0.3
            && $request->data()['thinking'] === ['type' => 'disabled']);
    }

    public function test_the_default_temperature_is_deterministic(): void
    {
        $this->assertSame(0.0, $this->app['config']->get('ai.template.temperature'));
        Http::fake([self::BETA => Http::response($this->toolAnswer('ver_plantilla', '{}'))]);

        app(AiAgentGateway::class)->step($this->request());

        Http::assertSent(fn (Request $request): bool => $request->data()['temperature'] === 0.0);
    }

    public function test_a_blank_temperature_leaves_the_provider_default(): void
    {
        config(['ai.template.temperature' => '']);
        Http::fake([self::BETA => Http::response($this->toolAnswer('ver_plantilla', '{}'))]);

        app(AiAgentGateway::class)->step($this->request());

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('temperature', $request->data()));
    }

    public function test_the_template_model_overrides_the_chat_model(): void
    {
        config(['ai.template.model' => 'deepseek-v4-pro']);
        Http::fake([self::BETA => Http::response($this->toolAnswer('ver_plantilla', '{}'))]);

        $step = app(AiAgentGateway::class)->step($this->request());

        $this->assertSame('deepseek:deepseek-v4-pro', $step->gatewayVersion);
        Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'deepseek-v4-pro');
    }

    public function test_without_strict_mode_it_uses_the_configured_endpoint(): void
    {
        config(['ai.template.strict_tools' => false, 'ai.hosted.base_url' => 'https://api.deepseek.com/chat/completions']);
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::response($this->toolAnswer('ver_plantilla', '{}'))]);

        app(AiAgentGateway::class)->step($this->request());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.deepseek.com/chat/completions'
            && ! array_key_exists('strict', (array) data_get($request->data(), 'tools.0.function')));
    }

    public function test_history_carries_tool_calls_and_their_results(): void
    {
        Http::fake([self::BETA => Http::response($this->toolAnswer('responder', '{"mensaje":"Listo"}'))]);
        $call = new AiToolCall('call-1', 'ver_plantilla', [], '{}');

        app(AiAgentGateway::class)->step($this->request([
            ['role' => 'user', 'content' => 'Pedido'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call]],
            ['role' => 'tool', 'tool_call_id' => 'call-1', 'content' => '{"secciones":[]}'],
        ]));

        Http::assertSent(function (Request $request): bool {
            $messages = $request->data()['messages'];

            return $messages[1] === ['role' => 'user', 'content' => 'Pedido']
                && $messages[2] === ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'ver_plantilla', 'arguments' => '{}']]]]
                && $messages[3] === ['role' => 'tool', 'tool_call_id' => 'call-1', 'content' => '{"secciones":[]}'];
        });
    }

    public function test_insufficient_balance_and_invalid_keys_are_not_retried(): void
    {
        Http::fake([self::BETA => Http::sequence()->push(['error' => ['message' => 'Insufficient Balance']], 402)->push([], 401)]);

        $this->assertProviderError(AiProviderError::NO_BALANCE);
        $this->assertProviderError(AiProviderError::INVALID_KEY);
        Http::assertSentCount(2);
    }

    public function test_a_busy_provider_is_retried_after_waiting(): void
    {
        Http::fake([self::BETA => Http::sequence()->push([], 429)->push([], 503)->push($this->toolAnswer('ver_plantilla', '{}'))]);

        $step = app(AiAgentGateway::class)->step($this->request());

        $this->assertSame('ver_plantilla', $step->toolCalls[0]->name);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_server_errors_give_up_after_the_retries(): void
    {
        Http::fake([self::BETA => Http::response([], 503)]);

        $this->assertProviderError(AiProviderError::UNAVAILABLE);
        Http::assertSentCount(3);
    }

    public function test_a_rejected_request_keeps_the_provider_detail(): void
    {
        Http::fake([self::BETA => Http::response(['error' => ['message' => 'Invalid tool schema']], 400)]);

        try {
            app(AiAgentGateway::class)->step($this->request());
            $this->fail('Debió rechazarse.');
        } catch (AiProviderError $exception) {
            $this->assertSame(AiProviderError::REJECTED, $exception->reason);
            $this->assertStringContainsString('Invalid tool schema', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_blank_and_cut_answers_break_the_contract(): void
    {
        Http::fake([self::BETA => Http::sequence()
            ->push(['choices' => [['message' => ['content' => '   '], 'finish_reason' => 'stop']]])
            ->push(['choices' => [['message' => ['content' => 'Lista incompleta'], 'finish_reason' => 'length']]])]);

        foreach (['en blanco', 'cortada'] as $reason) {
            try {
                app(AiAgentGateway::class)->step($this->request());
                $this->fail('Debió fallar.');
            } catch (AiContractException $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }
    }

    public function test_no_capacity_counts_as_busy(): void
    {
        Http::fake([self::BETA => Http::response(['choices' => [['message' => ['content' => null], 'finish_reason' => 'insufficient_system_resource']]])]);

        $this->assertProviderError(AiProviderError::BUSY);
    }

    public function test_invalid_arguments_are_kept_raw_for_the_assistant(): void
    {
        Http::fake([self::BETA => Http::response($this->toolAnswer('ver_campo', '{"clave":'))]);

        $step = app(AiAgentGateway::class)->step($this->request());

        $this->assertNull($step->toolCalls[0]->arguments);
        $this->assertSame('{"clave":', $step->toolCalls[0]->rawArguments);
    }

    public function test_only_deepseek_is_supported(): void
    {
        config(['ai.driver' => 'openai']);
        Http::fake();

        $this->assertProviderError(AiProviderError::UNAVAILABLE);
        Http::assertNothingSent();
    }

    private function assertProviderError(string $reason): void
    {
        try {
            app(AiAgentGateway::class)->step($this->request());
            $this->fail("Debió fallar con {$reason}.");
        } catch (AiProviderError $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    /**
     * @param  list<array{role: string, content?: string|null, tool_calls?: list<AiToolCall>, tool_call_id?: string}>  $messages
     */
    private function request(array $messages = [['role' => 'user', 'content' => 'Pedido']]): AiAgentRequest
    {
        return new AiAgentRequest('Reglas', $messages, [
            new AiToolSpec('ver_campo', 'Muestra un campo.', [
                'type' => 'object',
                'properties' => ['clave' => ['type' => 'string']],
                'required' => ['clave'],
                'additionalProperties' => false,
            ]),
        ], 'adm-prueba');
    }

    /**
     * @return array<string, mixed>
     */
    private function toolAnswer(string $name, string $arguments): array
    {
        return [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'call-1', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $arguments]],
                ]],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 40, 'prompt_cache_hit_tokens' => 1000],
        ];
    }
}
