<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class HeaderCellListTableTest extends TestCase
{
    use ConfiguresFacultyLogos, CreatesSyllabusProcess, RefreshDatabase;

    private function actAs(string $email): static
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();

        return $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
    }

    /** @return array<string, mixed> */
    private function cell(string $type, string $text = ''): array
    {
        return ['type' => $type, 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [
            $text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    /** @param list<array<string, mixed>> $lastRow
     * @return array<string, mixed> */
    private function table(array $lastRow): array
    {
        return ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [$this->cell('tableHeader', 'N°'), $this->cell('tableHeader', 'Actividad')]],
            ['type' => 'tableRow', 'content' => $lastRow],
        ]];
    }

    public function test_a_table_whose_last_row_has_an_empty_header_cell_does_not_accept_new_rows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        $table = $this->table([$this->cell('tableHeader'), $this->cell('tableCell')]);

        $this->assertFalse(FieldResponse::listTable($table));
        $this->assertFalse(FieldResponse::listTable($this->table([$this->cell('tableHeader'), $this->cell('tableHeader')])));
        $this->assertTrue(FieldResponse::listTable($this->table([$this->cell('tableCell'), $this->cell('tableCell')])));

        $this->actAs('admin@silabos.test')->postJson(route('api.v1.admin.template.store'))->assertCreated();
        $template = SyllabusTemplate::institutional();
        $this->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $field = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail();
        $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$template, $field]), ['content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Actividades']]],
            $table,
        ]]])->assertOk();
        $field->refresh();
        $this->fillSubjectProfiles();
        $this->actAs('coordinador@silabos.test')->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->actAs('docente@silabos.test')->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();

        $value = FieldResponse::template(FieldDocument::normalize($field->contenido));
        $value['content'][1]['content'][1]['content'][1]['content'][0]['content'] = [['type' => 'text', 'text' => 'Taller']];
        $this->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $value])->assertOk();

        $withExtraRow = $value;
        $withExtraRow['content'][1]['content'][] = $value['content'][1]['content'][1];
        $this->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $withExtraRow])
            ->assertUnprocessable();

        $stored = FieldValue::query()->where('silabo_id', $syllabus->id)->where('definicion_campo_id', $field->id)->firstOrFail()->valor;
        $this->assertCount(2, $stored['content'][1]['content']);
    }
}
