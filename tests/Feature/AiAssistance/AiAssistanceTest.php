<?php

namespace Tests\Feature\AiAssistance;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\AiAssistance\Application\BuildSyllabusChatContext;
use App\Modules\AiAssistance\Domain\Contracts\AiChatGateway;
use App\Modules\AiAssistance\Domain\Data\AiChatInput;
use App\Modules\AiAssistance\Domain\Data\AiChatResult;
use App\Modules\AiAssistance\Domain\Exceptions\AiGatewayUnavailable;
use App\Modules\AiAssistance\Infrastructure\Jobs\AnswerSyllabusChatJob;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Application\AcademicContextSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class AiAssistanceTest extends TestCase
{
    use CreatesSyllabusProcess, RefreshDatabase;

    private User $teacher;

    private RoleAssignment $teacherRole;

    private Syllabus $syllabus;

    private FieldDefinition $planning;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['ai.driver' => 'baseline', 'sanctum.stateful' => ['localhost:5173']]);
        $this->seed(DatabaseSeeder::class);
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->teacherRole = $this->teacher->roleAssignments()->firstOrFail();
        $this->fixture();
        $this->actingAs($this->teacher)->withSession(['active_role_assignment_id' => $this->teacherRole->id]);
    }

    public function test_review_analysis_feedback_and_application_routes_are_removed(): void
    {
        $base = '/api/v1/teacher/syllabi/'.$this->syllabus->id;
        $field = $base.'/fields/'.$this->planning->id.'/ai';
        $this->postJson($base.'/ai-review')->assertNotFound();
        $this->getJson($field)->assertNotFound();
        $this->postJson($field)->assertNotFound();
        $this->postJson($field.'/'.Str::uuid().'/decision')->assertNotFound();
        $this->postJson($field.'/'.Str::uuid().'/apply')->assertNotFound();
        $this->assertDatabaseCount('mensajes_ia', 0);
        Queue::assertNothingPushed();
    }

    public function test_unit_context_uses_frozen_curriculum_and_planning_without_sources_or_personal_fields(): void
    {
        Subject::query()->whereKey($this->syllabus->subject->id)->update(['nombre' => 'NOMBRE VIVO MODIFICADO']);
        DB::enableQueryLog();
        $items = app(BuildSyllabusChatContext::class)->build($this->syllabus->fresh());
        $queries = implode(' ', array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        $text = implode("\n", array_column($items, 'excerpt'));
        $this->assertStringContainsString('Carrera: Software', $text);
        $this->assertStringContainsString('Evalúa la calidad del software.', $text);
        $this->assertStringContainsString('Tema de unidad existente', $text);
        $this->assertStringNotContainsString('NOMBRE VIVO MODIFICADO', $text);
        $this->assertStringNotContainsString($this->teacher->correo_electronico, $text);
        $this->assertStringNotContainsString('documentos_importacion', $queries);
        $this->assertEqualsCanonicalizing(['contexto_academico', 'dato_curricular', 'seccion_silabo'], array_column($items, 'type'));
        $this->assertSame([$this->planning->id], array_values(array_filter(array_column($items, 'field_id'))));
    }

    public function test_chat_proposes_units_asynchronously_once_without_modifying_the_syllabus(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('conversation', null)->assertJsonPath('available', true)
            ->assertJsonMissingPath('review')->assertJsonMissingPath('review_results');
        $beforeValues = FieldValue::query()->get()->toArray();
        $key = (string) Str::uuid();
        $payload = ['message' => 'Propón contenidos para una unidad.', 'idempotency_key' => $key];
        $id = $this->postJson($this->url().'/messages', $payload)->assertAccepted()->json('assistant_message_id');
        $this->postJson($this->url().'/messages', $payload)->assertAccepted()->assertJsonPath('assistant_message_id', $id);
        Queue::assertPushed(AnswerSyllabusChatJob::class, 1);
        $gateway = $this->mock(AiChatGateway::class);
        $gateway->shouldReceive('reply')->once()->andReturnUsing(function (AiChatInput $input): AiChatResult {
            $this->assertSame('syllabus_unit_content', $input->toGatewayPayload()['task']);
            $this->assertNotContains('fuente_academica', array_column($input->evidence, 'type'));

            return new AiChatResult($input->requestId, 'fake-units', 'Propuesta: unidad de fundamentos de calidad.', [$input->evidence[0]['id']]);
        });
        (new AnswerSyllabusChatJob($id))->handle($gateway);
        $this->getJson($this->url())->assertOk()->assertJsonPath('conversation.messages.1.status', 'completado')
            ->assertJsonPath('conversation.messages.1.citations.0.type', 'contexto_academico');
        $this->assertSame($beforeValues, FieldValue::query()->get()->toArray());
        $this->assertSame(0, $this->syllabus->fresh()->version_bloqueo);
        $this->deleteJson($this->url())->assertOk();
        $this->getJson($this->url())->assertJsonPath('conversation.hidden', true)->assertJsonCount(2, 'conversation.messages');
        $this->assertDatabaseCount('mensajes_ia', 3);
    }

    public function test_pending_legacy_chat_is_not_sent_and_new_context_does_not_replay_legacy_history(): void
    {
        $question = AiHistoryEntry::query()->create([...AiHistoryEntry::chatSession($this->syllabus->id, $this->teacher->id),
            'contenido_mensaje' => ['tipo' => 'mensaje', 'rol' => 'usuario', 'estado' => 'completado', 'entrada' => ['contenido' => 'Historial RAG que no debe enviarse.']]]);
        $old = AiHistoryEntry::query()->create([...AiHistoryEntry::chatSession($this->syllabus->id, $this->teacher->id), 'contenido_mensaje' => [
            'tipo' => 'mensaje', 'rol' => 'asistente', 'estado' => 'pendiente', 'entrada' => [
                'respuesta_a_id' => $question->id, 'ejecucion_trabajo_id' => $this->job('ia.chat')->id,
                'evidencia' => [['id' => 'legacy', 'tipo' => 'fuente_academica', 'etiqueta' => 'Fuente', 'extracto' => 'NO ENVIAR']],
            ]]]);
        $never = $this->mock(AiChatGateway::class);
        $never->shouldNotReceive('reply');
        (new AnswerSyllabusChatJob($old->id))->handle($never);
        $this->assertSame('contexto_ia_retirado', $old->fresh()->part('codigo_error'));
        $this->assertSame('NO ENVIAR', $old->fresh()->evidence()[0]['extracto']);
        $id = $this->postJson($this->url().'/messages', ['message' => 'Propón una unidad.', 'idempotency_key' => (string) Str::uuid()])
            ->assertAccepted()->json('assistant_message_id');
        $gateway = $this->mock(AiChatGateway::class);
        $gateway->shouldReceive('reply')->once()->andReturnUsing(function (AiChatInput $input): AiChatResult {
            $this->assertStringNotContainsString('Historial RAG', json_encode($input->history));

            return new AiChatResult($input->requestId, 'fake-units', 'Propuesta de unidad.', []);
        });
        (new AnswerSyllabusChatJob($id))->handle($gateway);
    }

    public function test_invented_citations_fail_closed_and_unavailable_service_keeps_draft_usable(): void
    {
        $id = $this->postJson($this->url().'/messages', ['message' => 'Propón una unidad.', 'idempotency_key' => (string) Str::uuid()])
            ->assertAccepted()->json('assistant_message_id');
        $gateway = $this->mock(AiChatGateway::class);
        $gateway->shouldReceive('reply')->once()->andReturn(new AiChatResult($id, 'fake', 'Texto.', ['inventada']));
        (new AnswerSyllabusChatJob($id))->handle($gateway);
        $this->assertSame('contrato_ia_invalido', AiHistoryEntry::query()->findOrFail($id)->part('codigo_error'));
        $id = $this->postJson($this->url().'/messages', ['message' => 'Propón otra unidad.', 'idempotency_key' => (string) Str::uuid()])
            ->assertAccepted()->json('assistant_message_id');
        (new AnswerSyllabusChatJob($id))->failed(new AiGatewayUnavailable('Error remoto sensible'));
        $this->getJson($this->url())->assertOk()->assertJsonPath('conversation.messages.3.status', 'fallido');
        $this->assertSame('borrador', $this->syllabus->fresh()->estado);
        $this->assertSame(0, $this->syllabus->fresh()->version_bloqueo);
    }

    public function test_scope_rate_limit_and_terminal_message_immutability_remain_enforced(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson($this->url().'/messages', ['message' => 'Organiza los temas.', 'idempotency_key' => (string) Str::uuid()])->assertAccepted();
        }
        $this->postJson($this->url().'/messages', ['message' => 'Otra pregunta.', 'idempotency_key' => (string) Str::uuid()])->assertTooManyRequests();
        $this->getJson($this->url())->assertOk();
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->actingAs($coordinator)->withSession(['active_role_assignment_id' => $coordinator->roleAssignments()->firstOrFail()->id]);
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url().'/messages', ['message' => 'Texto.', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $question = AiHistoryEntry::query()->where('contenido_mensaje->rol', 'usuario')->firstOrFail();
        try {
            DB::transaction(fn () => $question->amend(['contenido' => 'Historia sobrescrita']));
            $this->fail('El historial terminal no puede reescribirse.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    private function fixture(): void
    {
        $template = SyllabusTemplate::query()->create(['nombre' => 'Plantilla de unidades', 'activo' => true]);
        $section = $template->appendSection(['id' => (string) Str::uuid(), 'clave' => 'planificacion', 'titulo' => 'Planificación', 'posicion' => 1, 'bloques' => []]);
        $block = $template->appendBlock($section->id, ['id' => (string) Str::uuid(), 'clave' => 'unidades', 'tipo' => 'narrativa', 'titulo' => 'Unidades', 'posicion' => 1,
            'configuracion' => ['content_type' => 'text', 'document' => TemplateDocumentDefaults::freeTextSheet('unidades', 'Planificación por unidades')]]);
        $this->planning = FieldDefinition::query()->create(['plantilla_id' => $template->id, 'campo_plantilla_id' => $block->id,
            'clave' => 'unidades', 'etiqueta' => 'Planificación por unidades', 'tipo' => 'texto_libre', 'heredado' => false,
            'editable_docente' => true, 'posicion' => 1]);
        $privateBlock = $template->appendBlock($section->id, ['id' => (string) Str::uuid(), 'clave' => 'contacto', 'tipo' => 'narrativa', 'titulo' => 'Otros campos', 'posicion' => 2, 'configuracion' => []]);
        $private = FieldDefinition::query()->create(['plantilla_id' => $template->id, 'campo_plantilla_id' => $privateBlock->id,
            'clave' => 'contacto', 'etiqueta' => 'Contacto', 'tipo' => 'texto_largo', 'heredado' => false,
            'editable_docente' => true, 'posicion' => 1]);
        $parallel = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail()->parallel;
        $scheduled = $parallel->scheduledSubject;
        $context = app(AcademicContextSnapshot::class)->build($scheduled);
        $context['career']['name'] = 'Software';
        $context['curricular'] = ['definitions' => ['resultados_aprendizaje' => ['key' => 'resultados_aprendizaje', 'label' => 'Resultados', 'type' => 'lista', 'columns' => []]],
            'values' => ['resultados_aprendizaje' => ['Evalúa la calidad del software.']], 'evidence' => []];
        $convocation = Convocation::query()->create(['carrera_id' => $scheduled->subject->carrera_id, 'proceso_id' => $this->openSyllabusProcess($template->id)->id, 'estado' => 'abierta']);
        $this->syllabus = Syllabus::query()->create(['convocatoria_id' => $convocation->id, 'paralelo_id' => $parallel->id,
            'contexto_academico' => $context, 'estado' => 'borrador', 'version_bloqueo' => 0, 'iniciado_en' => now()]);
        FieldValue::query()->create(['silabo_id' => $this->syllabus->id, 'definicion_campo_id' => $this->planning->id,
            'valor' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Tema de unidad existente']]]]]]);
        FieldValue::query()->create(['silabo_id' => $this->syllabus->id, 'definicion_campo_id' => $private->id,
            'valor' => 'NO ENVIAR: '.$this->teacher->correo_electronico]);
    }

    private function job(string $type): JobExecution
    {
        return JobExecution::query()->create(['tipo' => $type, 'cola' => 'ia', 'estado' => 'pendiente', 'clave_idempotencia' => (string) Str::uuid(),
            'tipo_recurso' => 'mensaje_ia', 'recurso_id' => (string) Str::uuid(),
            'intentos' => 0, 'intentos_maximos' => 1, 'progreso' => 0]);
    }

    private function url(): string
    {
        return route('api.v1.teacher.syllabi.ai-chat.show', $this->syllabus);
    }
}
