<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

use App\Modules\AiAssistance\Domain\Contracts\AiAgentGateway;
use App\Modules\AiAssistance\Domain\Data\AiAgentRequest;
use App\Modules\AiAssistance\Domain\Data\AiAgentStep;
use App\Modules\AiAssistance\Domain\Data\AiToolCall;
use App\Modules\AiAssistance\Domain\Exceptions\AiContractException;
use App\Modules\AiAssistance\Domain\Exceptions\AiGatewayUnavailable;
use App\Modules\AiAssistance\Domain\Exceptions\AiProviderError;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Support\Str;

final class TemplateAssistant
{
    public const STEP_LIMIT = 'limite_pasos';

    public const TIME_LIMIT = 'limite_tiempo';

    public const TOO_MANY_ERRORS = 'demasiados_errores';

    private const UNAVAILABLE = 'El asistente no está disponible por ahora. Puede seguir editando la plantilla.';

    private const RETRY = 'No pude completar el pedido. Intente explicarlo de otra forma.';

    private const MESSAGES = [
        AiProviderError::NO_BALANCE => self::UNAVAILABLE,
        AiProviderError::INVALID_KEY => self::UNAVAILABLE,
        AiProviderError::UNAVAILABLE => self::UNAVAILABLE,
        AiProviderError::BUSY => 'El asistente está ocupado. Inténtelo en unos minutos.',
        AiProviderError::REJECTED => 'El asistente no pudo procesar el pedido. Inténtelo de nuevo.',
        self::STEP_LIMIT => self::RETRY,
        self::TIME_LIMIT => self::RETRY,
        self::TOO_MANY_ERRORS => self::RETRY,
    ];

    /** @var list<array{paso: int, funcion: string, argumentos: string, resultado: string}> */
    private array $trace = [];

    /** @var array{prompt: int, completion: int, cached: int} */
    private array $usage = ['prompt' => 0, 'completion' => 0, 'cached' => 0];

    private string $gatewayVersion = '';

    /** @var list<string> */
    private array $preexisting = [];

    public function __construct(
        private readonly AiAgentGateway $gateway,
        private readonly TemplateAssistantKnowledge $knowledge,
        private readonly TemplateDraftValidator $validator,
    ) {}

    public static function userTag(string $userId): string
    {
        return 'adm-'.substr(hash_hmac('sha256', $userId, (string) config('app.key')), 0, 32);
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $history
     * @param  list<array{key: string, title: string, orientation: string, fields: list<array{key: string, label: string, quien_llena: string, content: array<string, mixed>}>}>|null  $editorDraft
     * @param  array{estado: string, cambios: list<string>}|null  $lastProposal
     */
    public function run(SyllabusTemplate $template, string $request, array $history = [], string $userTag = 'consola', ?array $editorDraft = null, ?array $lastProposal = null): TemplateAssistantOutcome
    {
        $this->trace = [];
        $this->usage = ['prompt' => 0, 'completion' => 0, 'cached' => 0];
        $this->gatewayVersion = '';
        $draft = $editorDraft === null ? TemplateDraft::fromTemplate($template) : TemplateDraft::fromDraft($template, $editorDraft);
        $this->preexisting = $this->validator->errors($draft);
        $tools = new TemplateTools($draft, $this->validator, $this->processState(), $this->preexisting);
        $system = $this->knowledge->system();
        $messages = [...$history, ['role' => 'user', 'content' => $this->opening($tools, $request, $editorDraft !== null, $history !== [], $lastProposal)]];
        $deadline = now()->addSeconds((int) config('ai.template.max_seconds'));
        $maxSteps = (int) config('ai.template.max_steps');
        $maxErrors = (int) config('ai.template.max_consecutive_errors');
        $errors = 0;

        for ($step = 1; $step <= $maxSteps; $step++) {
            if (now()->greaterThan($deadline)) {
                return $this->failed(self::TIME_LIMIT, $step - 1);
            }
            try {
                $answer = $this->gateway->step(new AiAgentRequest($system, $messages, $tools->specs(), $userTag));
            } catch (AiContractException $exception) {
                $this->record($step, '(respuesta inválida)', '', 'error: '.$exception->getMessage());
                if (++$errors > $maxErrors) {
                    return $this->failed(self::TOO_MANY_ERRORS, $step);
                }

                continue;
            } catch (AiProviderError $exception) {
                return $this->failed($exception->reason, $step);
            } catch (AiGatewayUnavailable) {
                return $this->failed(AiProviderError::UNAVAILABLE, $step);
            }
            $this->account($answer);
            $calls = $answer->toolCalls !== [] ? $answer->toolCalls : [$this->implicitAnswer($answer)];
            $messages[] = ['role' => 'assistant', 'content' => $answer->content, 'tool_calls' => $calls];
            $finish = null;
            $failed = false;
            foreach ($calls as $call) {
                $result = $this->execute($tools, $draft, $call);
                $this->record($step, $call->name, $call->rawArguments, $result->failed ? 'error: '.$this->errorOf($result) : 'ok');
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call->id, 'content' => $result->content];
                $failed = $failed || $result->failed;
                $finish ??= $result->terminal !== null ? $result : null;
            }
            if ($finish !== null && $finish->message !== null) {
                return $this->finished($finish, $draft, $step);
            }
            $errors = $failed ? $errors + 1 : 0;
            if ($errors > $maxErrors) {
                return $this->failed(self::TOO_MANY_ERRORS, $step);
            }
        }

        return $this->failed(self::STEP_LIMIT, $maxSteps);
    }

    private function execute(TemplateTools $tools, TemplateDraft $draft, AiToolCall $call): TemplateToolResult
    {
        $result = $tools->execute($call);
        if ($result->terminal !== TemplateToolResult::ANSWER || ! $draft->changed()) {
            return $result;
        }
        $errors = array_values(array_diff($this->validator->errors($draft), $this->preexisting));

        return $errors === []
            ? $result
            : TemplateToolResult::error('El borrador tiene errores; corríjalos antes de responder: '.implode(' | ', $errors));
    }

    private function finished(TemplateToolResult $finish, TemplateDraft $draft, int $steps): TemplateAssistantOutcome
    {
        $message = (string) $finish->message;
        if ($finish->terminal === TemplateToolResult::QUESTION) {
            return $this->outcome(TemplateAssistantOutcome::QUESTION, $message, null, null, $steps);
        }
        if (! $draft->changed()) {
            return $this->outcome(TemplateAssistantOutcome::ANSWER, $message, null, null, $steps);
        }

        return $this->outcome(TemplateAssistantOutcome::PROPOSAL, $message, [
            'payload' => $draft->payload(),
            'changes' => $draft->changes(),
            'changed' => $draft->changedKeys(),
            'fingerprint' => $draft->baseFingerprint(),
        ], null, $steps);
    }

    private function failed(string $code, int $steps): TemplateAssistantOutcome
    {
        return $this->outcome(TemplateAssistantOutcome::FAILED, self::MESSAGES[$code] ?? self::UNAVAILABLE, null, $code, $steps);
    }

    /**
     * @param  array{payload: array{version: int, sections: list<array{key: string, title: string, orientation: string, fields: list<array{key: string, label: string, quien_llena: string, content: array<string, mixed>}>}>}, changes: list<string>, changed: array{secciones: list<string>, campos: list<string>}, fingerprint: string}|null  $proposal
     */
    private function outcome(string $status, string $message, ?array $proposal, ?string $code, int $steps): TemplateAssistantOutcome
    {
        return new TemplateAssistantOutcome($status, $message, $proposal, $code, $this->trace, $this->usage, $steps, $this->gatewayVersion);
    }

    private function implicitAnswer(AiAgentStep $answer): AiToolCall
    {
        $arguments = ['mensaje' => (string) $answer->content];

        return new AiToolCall('implicita-'.Str::random(8), 'responder', $arguments, json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function account(AiAgentStep $answer): void
    {
        $this->gatewayVersion = $answer->gatewayVersion;
        foreach ($this->usage as $key => $value) {
            $this->usage[$key] = $value + $answer->usage[$key];
        }
    }

    private function record(int $step, string $tool, string $arguments, string $result): void
    {
        $this->trace[] = [
            'paso' => $step,
            'funcion' => $tool,
            'argumentos' => Str::limit($arguments, 300),
            'resultado' => Str::limit($result, 300),
        ];
    }

    private function errorOf(TemplateToolResult $result): string
    {
        $decoded = json_decode($result->content, true);

        return is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : 'error';
    }

    /**
     * @param  array{estado: string, cambios: list<string>}|null  $lastProposal
     */
    private function opening(TemplateTools $tools, string $request, bool $fromEditor, bool $continues, ?array $lastProposal): string
    {
        $state = $tools->locked()
            ? 'La convocatoria está abierta: solo puede consultar y explicar; no proponga cambios.'
            : 'Puede proponer cambios en el borrador. Nada se guarda hasta que el administrador los aplique.';
        if ($fromEditor) {
            $state .= ' El borrador es lo que el administrador tiene ahora en el editor, con sus cambios sin guardar. Las propuestas anteriores que no aplicó no están en el borrador.';
        }
        if ($lastProposal !== null) {
            $state .= "\n\n".$this->lastProposalNote($lastProposal);
        }
        if ($this->preexisting !== []) {
            $state .= "\n\nEl borrador ya tenía estos problemas; no los corrija salvo que se lo pidan:\n- ".implode("\n- ", $this->preexisting);
        }

        $label = $continues
            ? 'Nuevo mensaje del administrador (puede ser la respuesta a su última pregunta; léalo con la conversación anterior)'
            : 'Pedido del administrador';

        return "{$state}\n\n{$label}:\n".trim($request);
    }

    /**
     * @param  array{estado: string, cambios: list<string>}  $proposal
     */
    private function lastProposalNote(array $proposal): string
    {
        $changes = $proposal['cambios'] === [] ? '' : "\n- ".implode("\n- ", $proposal['cambios']);

        return match ($proposal['estado']) {
            'aplicada' => 'Su última propuesta ya está aplicada: sus cambios están en el borrador.',
            'pendiente' => 'Su última propuesta sigue pendiente: el administrador aún no la aplica y sus cambios NO están en el borrador. Si ahora solo la aprueba («dale», «sí», «perfecto»), pídale que pulse «Aplicar» en esa propuesta y no cambie nada. Si pide algo más, vuelva a incluir esos cambios en la nueva propuesta, porque la nueva reemplaza a la anterior. Cambios de esa propuesta:'.$changes,
            'descartada' => 'Su última propuesta fue descartada: el administrador no la quiso. No la repita salvo que se la pida.',
            default => 'Su última propuesta ya no coincide con el borrador, porque el administrador siguió editando. Sus cambios no están en el borrador.',
        };
    }

    /**
     * @return array{estado: string, aviso: string}
     */
    private function processState(): array
    {
        $state = collect([SyllabusProcess::STATE_OPEN, SyllabusProcess::STATE_PAUSED])
            ->first(fn (string $state): bool => SyllabusProcess::query()->where('estado', $state)->exists());

        return match ($state) {
            SyllabusProcess::STATE_OPEN => ['estado' => 'abierta', 'aviso' => 'Hay una convocatoria abierta: la plantilla no se puede cambiar hasta pausarla.'],
            SyllabusProcess::STATE_PAUSED => ['estado' => 'pausada', 'aviso' => 'La convocatoria está en pausa: si se aplican cambios, al reanudarla los sílabos en curso se rehacen desde cero.'],
            default => ['estado' => 'ninguna', 'aviso' => 'No hay convocatoria abierta ni en pausa: los cambios se aplican a los próximos sílabos.'],
        };
    }
}
