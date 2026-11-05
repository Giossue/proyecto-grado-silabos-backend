<?php

namespace Tests\Feature\AiAssistance;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TeacherChatRemovedTest extends TestCase
{
    public function test_the_teacher_ai_chat_no_longer_exists_and_the_template_assistant_remains(): void
    {
        foreach (['show', 'messages.store', 'clear'] as $action) {
            $this->assertFalse(Route::has('api.v1.teacher.syllabi.ai-chat.'.$action));
        }

        $this->assertTrue(Route::has('api.v1.admin.template.assistant.show'));
    }
}
