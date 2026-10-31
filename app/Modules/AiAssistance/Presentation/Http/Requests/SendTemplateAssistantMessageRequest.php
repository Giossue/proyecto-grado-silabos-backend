<?php

namespace App\Modules\AiAssistance\Presentation\Http\Requests;

use App\Modules\Configuration\Presentation\Http\Requests\ManageTemplatesRequest;

class SendTemplateAssistantMessageRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.config('ai.limits.chat_message_characters')],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['message' => 'pedido', 'idempotency_key' => 'clave del pedido'];
    }
}
