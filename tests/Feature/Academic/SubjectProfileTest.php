<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubjectProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->asRole('admin@silabos.test');
        $this->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla de ficha']);
        $this->postJson(route('api.v1.admin.template.format', SyllabusTemplate::query()->firstOrFail()))->assertSuccessful();
    }

    public function test_the_profile_form_is_built_from_the_template_fields_marked_from_profile(): void
    {
        $subject = Subject::query()->firstOrFail();
        $this->asRole('coordinador@silabos.test');

        $response = $this->getJson(route('api.v1.academic.subjects.profile.show', $subject))->assertOk();
        $keys = array_column($response->json('fields'), 'key');
        $this->assertContains('descripcion', $keys);
        $this->assertNotContains('bibliografia', $keys);
        $this->assertNotContains('recursos_medios', $keys);
        $this->assertFalse($response->json('complete'));
        $this->assertSame([], $response->json('values'));
    }

    public function test_coordinator_and_administrator_save_values_and_the_profile_completes(): void
    {
        $subject = Subject::query()->firstOrFail();
        $fields = FieldDefinition::query()->where('quien_llena', 'coordinacion')->get();
        $values = $fields->mapWithKeys(fn (FieldDefinition $field): array => [$field->id => FreeText::fromText("Texto de {$field->clave}")])->all();

        $this->asRole('coordinador@silabos.test');
        $this->putJson(route('api.v1.academic.subjects.profile.update', $subject), ['values' => $values])
            ->assertOk()->assertJsonPath('complete', true);
        $this->assertSame($fields->count(), FieldValue::query()->where('asignatura_id', $subject->id)->count());
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.ficha_asignatura.guardada']);

        $this->asRole('admin@silabos.test');
        $first = $fields->first();
        $this->putJson(route('api.v1.academic.subjects.profile.update', $subject), ['values' => [$first->id => null]])
            ->assertOk()->assertJsonPath('complete', false);
        $this->assertSame($fields->count() - 1, FieldValue::query()->where('asignatura_id', $subject->id)->count());
    }

    public function test_another_career_and_teacher_cannot_access_the_profile(): void
    {
        $subject = Subject::query()->firstOrFail();
        $career = Career::query()->firstOrFail()->replicate();
        $career->codigo_carrera = 'OTRA';
        $career->save();
        $foreign = $subject->replicate();
        $foreign->carrera_id = $career->id;
        $foreign->save();

        $this->asRole('coordinador@silabos.test');
        $this->getJson(route('api.v1.academic.subjects.profile.show', $foreign))->assertForbidden();
        $this->putJson(route('api.v1.academic.subjects.profile.update', $foreign), ['values' => []])->assertForbidden();

        $this->asRole('docente@silabos.test');
        $this->getJson(route('api.v1.academic.subjects.profile.show', $subject))->assertForbidden();
        $this->putJson(route('api.v1.academic.subjects.profile.update', $subject), ['values' => []])->assertForbidden();
    }

    public function test_only_profile_fields_and_valid_documents_are_accepted(): void
    {
        $subject = Subject::query()->firstOrFail();
        $profileField = FieldDefinition::query()->where('quien_llena', 'coordinacion')->firstOrFail();
        $teacherField = FieldDefinition::query()->where('clave', 'recursos_medios')->firstOrFail();
        $this->asRole('coordinador@silabos.test');

        $this->putJson(route('api.v1.academic.subjects.profile.update', $subject), ['values' => [$teacherField->id => FreeText::fromText('No')]])
            ->assertUnprocessable()->assertJsonValidationErrors('values');
        $this->putJson(route('api.v1.academic.subjects.profile.update', $subject), ['values' => [Str::uuid()->toString() => FreeText::fromText('No')]])
            ->assertUnprocessable()->assertJsonValidationErrors('values');
        $this->putJson(route('api.v1.academic.subjects.profile.update', $subject), ['values' => [$profileField->id => ['type' => 'html']]])
            ->assertUnprocessable();
    }

    public function test_a_new_profile_field_appears_without_programming(): void
    {
        $template = SyllabusTemplate::query()->firstOrFail();
        $section = $template->sections->first();
        $this->asRole('admin@silabos.test');
        $this->postJson(route('api.v1.admin.template.structure.fields.store', [$template, $section->id]), [
            'section_id' => $section->id, 'key' => 'vinculacion', 'label' => 'Vinculación con la sociedad',
            'quien_llena' => 'coordinacion', 'content' => FreeText::fromText(''),
        ])->assertSuccessful();

        $subject = Subject::query()->firstOrFail();
        $this->asRole('coordinador@silabos.test');
        $fields = $this->getJson(route('api.v1.academic.subjects.profile.show', $subject))->assertOk()->json('fields');
        $this->assertContains('vinculacion', array_column($fields, 'key'));
    }

    public function test_the_initial_content_of_a_field_can_be_modified_or_removed(): void
    {
        $template = SyllabusTemplate::query()->firstOrFail();
        $field = FieldDefinition::query()->where('clave', 'tecnicas_ensenanza')->firstOrFail();
        $this->asRole('admin@silabos.test');
        $payload = ['label' => $field->etiqueta, 'quien_llena' => 'docente'];
        $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$template, $field]), [...$payload, 'content' => FreeText::fromText('Texto inicial sugerido')])->assertSuccessful();
        $this->assertSame('Texto inicial sugerido', FreeText::plainText($field->fresh()->contenido));
        $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$template, $field]), [...$payload, 'content' => FreeText::fromText('')])->assertSuccessful();
        $this->assertTrue(FreeText::isEmpty($field->fresh()->contenido));
    }

    public function test_a_value_belongs_to_exactly_one_owner(): void
    {
        $subject = Subject::query()->firstOrFail();
        $field = FieldDefinition::query()->where('quien_llena', 'coordinacion')->firstOrFail();

        $this->expectException(QueryException::class);
        FieldValue::query()->create(['definicion_campo_id' => $field->id, 'valor' => FreeText::fromText('Sin dueño')]);
        $this->assertNotNull($subject);
    }

    public function test_one_value_per_subject_and_field_is_enforced_by_postgresql(): void
    {
        $subject = Subject::query()->firstOrFail();
        $field = FieldDefinition::query()->where('quien_llena', 'coordinacion')->firstOrFail();
        FieldValue::query()->create(['asignatura_id' => $subject->id, 'definicion_campo_id' => $field->id, 'valor' => FreeText::fromText('Uno')]);

        $this->expectException(QueryException::class);
        FieldValue::query()->create(['asignatura_id' => $subject->id, 'definicion_campo_id' => $field->id, 'valor' => FreeText::fromText('Dos')]);
    }

    private function asRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $role = RoleAssignment::query()->where('usuario_id', $user->id)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $role->id]);
    }
}
