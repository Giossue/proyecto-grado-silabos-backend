<?php

namespace App\Modules\AiAssistance\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendAiChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit', $this->route('syllabus')) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.config('ai.limits.chat_message_characters')],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
