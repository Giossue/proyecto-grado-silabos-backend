<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\TestCase;

class TemplateDraftRehearsalTest extends TestCase
{
    use ConfiguresFacultyLogos;
    use RefreshDatabase;

    private SyllabusTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        $this->actingAsRole('admin@silabos.test');
        $this->postJson(route('api.v1.admin.template.store'), [])->assertCreated();
        $this->template = SyllabusTemplate::query()->firstOrFail();
        $this->postJson(route('api.v1.admin.template.format', $this->template))->assertOk();
        $this->fillSubjectProfiles();
    }

    public function test_the_draft_teacher_preview_shows_the_unsaved_draft_and_writes_nothing(): void
    {
        $draft = $this->draft();
        $draft['sections'][] = ['key' => 'anexos', 'title' => 'Anexos', 'orientation' => 'portrait', 'fields' => [
            ['key' => 'anexo', 'label' => 'Anexo', 'quien_llena' => 'carrera', 'content' => ['<p>Adjunte los anexos.</p>']],
        ]];
        $before = $this->state();

        $response = $this->postJson(route('api.v1.admin.template.draft.preview', $this->template), $draft)->assertOk()
            ->assertJsonStructure(['sections' => [['id', 'key', 'title', 'fields' => [['id', 'label', 'quien_llena', 'teacher_editable', 'value', 'document']]]], 'sheets' => [['id', 'key', 'title', 'orientation', 'fields' => [['id', 'key', 'label', 'quien_llena', 'content']]]]]);

        $teacher = collect($response->json('sections'))->firstWhere('key', 'anexos');
        $this->assertSame('Anexo', $teacher['fields'][0]['label']);
        $this->assertTrue($teacher['fields'][0]['teacher_editable']);
        $this->assertSame('Anexos', collect($response->json('sheets'))->last()['title']);
        $this->assertSame($before, $this->state());
        $this->assertNull(SyllabusTemplate::institutional()->sections()->firstWhere('clave', 'anexos'));
        $this->getJson(route('api.v1.admin.template.teacher-preview', $this->template))->assertOk()
            ->assertJsonMissing(['key' => 'anexos']);
    }

    public function test_the_saved_template_teacher_preview_still_works_without_a_draft(): void
    {
        $sections = $this->getJson(route('api.v1.admin.template.teacher-preview', $this->template))->assertOk()->json('sections');

        $this->assertCount($this->template->fresh()->sections()->count(), $sections);
    }

    public function test_only_the_administrator_rehearses_or_checks_a_draft(): void
    {
        $draft = $this->draft();
        $this->actingAsRole('docente@silabos.test');

        $this->postJson(route('api.v1.admin.template.draft.preview', $this->template), $draft)->assertForbidden();
        $this->postJson(route('api.v1.admin.template.draft.impact', $this->template), $draft)->assertForbidden();
        $this->postJson(route('api.v1.admin.template.draft.check', $this->template), $draft)->assertForbidden();
    }

    public function test_a_draft_without_a_paused_convocation_affects_no_syllabus_and_saves_as_before(): void
    {
        $draft = $this->draft();

        $this->postJson(route('api.v1.admin.template.draft.impact', $this->template), $draft)->assertOk()
            ->assertExactJson(['affected' => false, 'counted' => true, 'in_progress' => 0, 'keep' => 0, 'lose' => 0, 'back_to_draft' => 0, 'careers' => []]);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertOk()
            ->assertJsonPath('message', 'Plantilla importada desde el JSON.');
    }

    public function test_the_impact_of_a_draft_matches_what_saving_and_resuming_then_does(): void
    {
        [$process, $kept, $losing, $approved] = $this->pausedConvocationWithWork();
        $draft = $this->draft();
        foreach ($draft['sections'] as &$section) {
            foreach ($section['fields'] as &$field) {
                if ($field['key'] === 'tecnicas_ensenanza') {
                    $field['content'] = ['<p>Indique las técnicas por unidad.</p><p></p>'];
                }
            }
            unset($field);
        }
        unset($section);
        $before = $this->state();

        $impact = $this->postJson(route('api.v1.admin.template.draft.impact', $this->template), $draft)->assertOk()->json();

        $this->assertSame($before, $this->state());
        $career = Convocation::query()->firstOrFail()->career()->firstOrFail()->nombre;
        $this->assertSame(['affected' => true, 'counted' => true, 'in_progress' => 3, 'keep' => 2, 'lose' => 1, 'back_to_draft' => 1, 'careers' => [
            ['name' => $career, 'in_progress' => 3, 'keep' => 2, 'lose' => 1, 'back_to_draft' => 1],
        ]], $impact);

        $states = Syllabus::query()->pluck('estado', 'silabo_id')->all();
        $written = $this->teacherText([$kept->id, $losing->id, $approved->id]);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertOk();
        $this->postJson(route('api.v1.admin.processes.transition', [$process, 'reanudar']), ['confirmar_rehacer' => true])->assertSuccessful();

        $backToDraft = collect($states)->filter(fn (string $state, string $id): bool => in_array($state, ['en_revision', 'correccion_solicitada', 'aprobado'], true) && Syllabus::query()->findOrFail($id)->estado === 'borrador')->count();
        $after = $this->teacherText([$kept->id, $losing->id, $approved->id]);
        $lost = collect($written)->filter(fn (array $texts, string $id): bool => array_diff($texts, $after[$id]) !== [])->count();
        $this->assertSame($impact['back_to_draft'], $backToDraft);
        $this->assertSame($impact['lose'], $lost);
        $this->assertSame($impact['keep'], count($written) - $lost);
    }

    public function test_an_impact_that_runs_out_of_time_says_it_could_not_count_and_writes_nothing(): void
    {
        $this->pausedConvocationWithWork();
        $draft = $this->draft();
        $before = $this->state();
        config(['templates.rehearsal.statement_timeout' => '1ms']);

        $this->postJson(route('api.v1.admin.template.draft.impact', $this->template), $draft)->assertOk()
            ->assertJsonPath('counted', false)
            ->assertJsonPath('affected', true)
            ->assertJsonPath('message', 'No se pudo calcular qué sílabos cambian ahora.');
        $this->postJson(route('api.v1.admin.template.draft.preview', $this->template), $draft)->assertStatus(422)
            ->assertJsonPath('errors.draft.0', 'No se pudo armar la vista previa ahora. Intente de nuevo.');

        config(['templates.rehearsal.statement_timeout' => '20s']);
        $this->assertSame($before, $this->state());
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertOk();
    }

    public function test_the_live_check_points_each_problem_to_its_place_and_blocks_only_what_saving_rejects(): void
    {
        $draft = $this->draft();
        $draft['sections'][1]['fields'][0]['key'] = $draft['sections'][0]['fields'][0]['key'];
        $draft['sections'][2]['title'] = '';
        $draft['sections'][] = ['key' => 'vacia', 'title' => 'Vacía', 'orientation' => 'portrait', 'fields' => []];

        $problems = collect($this->postJson(route('api.v1.admin.template.draft.check', $this->template), $draft)->assertOk()->json('problems'));
        $saving = $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertStatus(422)->json('errors');

        $blocking = $problems->where('blocking', true);
        $this->assertEqualsCanonicalizing(array_keys($saving), $blocking->pluck('path')->unique()->all());
        foreach ($saving as $path => $messages) {
            $this->assertSame($messages, $blocking->where('path', $path)->pluck('message')->values()->all());
        }
        $this->assertContains('sections.0.fields.0.key', $blocking->pluck('path')->all());
        $this->assertContains('sections.2.title', $blocking->pluck('path')->all());
        $empty = $problems->firstWhere('path', 'sections.'.(count($draft['sections']) - 1));
        $this->assertFalse($empty['blocking']);
        $this->assertSame('La sección «Vacía» no tiene campos; agregue al menos uno o quítela.', $empty['message']);

        $onlyEmpty = $this->draft();
        $onlyEmpty['sections'][] = ['key' => 'vacia', 'title' => 'Vacía', 'orientation' => 'portrait', 'fields' => []];
        $this->postJson(route('api.v1.admin.template.draft.check', $this->template), $onlyEmpty)->assertOk()
            ->assertJsonPath('problems.0.blocking', false)->assertJsonCount(1, 'problems');
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $onlyEmpty)->assertOk();
    }

    public function test_the_live_check_marks_a_response_in_a_field_that_cannot_be_edited_like_saving_does(): void
    {
        $draft = $this->draft();
        $draft['sections'][1]['fields'][0]['quien_llena'] = 'institucional';
        $draft['sections'][1]['fields'][0]['content'] = ['<p>Modalidad: <span data-response=\'{"kind":"texto"}\'></span></p>'];

        $saving = $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertStatus(422)->json('errors');
        $problems = collect($this->postJson(route('api.v1.admin.template.draft.check', $this->template), $draft)->assertOk()->json('problems'))->where('blocking', true);

        $this->assertSame(['sections.1.fields.0.content'], $problems->pluck('path')->unique()->values()->all());
        $this->assertSame(array_values($saving)[0], $problems->pluck('message')->values()->all());
    }

    public function test_the_template_saves_fifteen_times_in_a_row_while_syllabi_keep_retired_fields(): void
    {
        $this->pausedConvocationWithWork();
        $draft = $this->draft();

        foreach (range(1, 15) as $save) {
            $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertOk();
        }

        $this->assertGreaterThan(0, DB::table('secciones_plantilla')->where('vigente_en_plantilla', false)->count());
        $this->assertLessThanOrEqual(120, (int) DB::table('secciones_plantilla')->max(DB::raw('length(clave_seccion_plantilla)')));
        $this->postJson(route('api.v1.admin.template.draft.preview', $this->template), $draft)->assertOk();
    }

    public function test_a_save_does_not_rename_sections_retired_before(): void
    {
        $this->pausedConvocationWithWork();
        $draft = $this->draft();
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertOk();
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertOk();
        $retired = DB::table('secciones_plantilla')->where('vigente_en_plantilla', false)->pluck('clave_seccion_plantilla', 'seccion_plantilla_id')->all();

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertOk();

        $this->assertNotEmpty($retired);
        $after = DB::table('secciones_plantilla')->whereIn('seccion_plantilla_id', array_keys($retired))->pluck('clave_seccion_plantilla', 'seccion_plantilla_id')->all();
        $this->assertNotEmpty($after);
        $expected = array_intersect_key($retired, $after);
        ksort($expected);
        ksort($after);
        $this->assertSame($expected, $after);
        foreach ($retired as $id => $key) {
            $this->assertStringEndsWith('-'.substr((string) $id, 0, 8), $key);
            $this->assertSame(1, substr_count($key, '-'));
        }
        $live = DB::table('secciones_plantilla')->where('vigente_en_plantilla', true)->pluck('clave_seccion_plantilla')->all();
        $this->assertSame(array_column($draft['sections'], 'key'), array_values(array_intersect(array_column($draft['sections'], 'key'), $live)));
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        $json = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        return ['version' => 1, 'title' => $json['document']['title']['text'], 'sections' => $json['sections']];
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $rows = fn (string $table): string => md5((string) json_encode(DB::table($table)->orderByRaw('1')->get()));

        return [
            'template' => md5((string) json_encode(DB::table('plantilla_silabo')->get())),
            'sections' => $rows('secciones_plantilla'),
            'fields' => $rows('campos_plantilla'),
            'definitions' => $rows('definiciones_campo'),
            'values' => $rows('valores_campo'),
            'syllabi' => $rows('silabos'),
            'audit' => DB::table('eventos_auditoria')->count(),
            'notifications' => DB::table('notificaciones')->count(),
        ];
    }

    /** @return array{SyllabusProcess, Syllabus, Syllabus, Syllabus} */
    private function pausedConvocationWithWork(): array
    {
        $this->postJson(route('api.v1.admin.processes.store'), [
            'period_id' => AcademicPeriod::query()->valueOrFail('id'),
            'starts_at' => now()->toIso8601String(),
            'due_at' => now()->addMonth()->toIso8601String(),
            'nombre' => 'Proceso abierto',
        ])->assertSuccessful();
        $process = SyllabusProcess::query()->firstOrFail();
        $this->actingAsRole('coordinador@silabos.test');
        $this->postJson(route('api.v1.coordination.convocations.store'), ['process_id' => $process->id])->assertSuccessful();
        $first = Syllabus::query()->orderBy('silabo_id')->firstOrFail();
        $this->actingAsRole('docente@silabos.test');
        $this->postJson(route('api.v1.teacher.syllabi.start', $first))->assertSuccessful();
        $copies = [];
        foreach (['I-COPIA-1' => 'borrador', 'I-COPIA-2' => 'aprobado'] as $code => $state) {
            $parallel = $first->parallel->replicate();
            $parallel->codigo = $code;
            $parallel->save();
            $copy = Syllabus::query()->create(['convocatoria_id' => $first->convocatoria_id, 'paralelo_id' => $parallel->id, 'contexto_academico' => $first->contexto_academico, 'estado' => $state]);
            foreach (FieldValue::query()->where('silabo_id', $first->id)->get() as $value) {
                FieldValue::query()->create(['silabo_id' => $copy->id, 'definicion_campo_id' => $value->definicion_campo_id, 'valor' => $value->valor]);
            }
            $copies[] = $copy;
        }
        [$kept, $losing, $approved] = [$first->fresh(), $copies[0], $copies[1]];
        $answer = fn (Syllabus $syllabus, string $key, string $text) => FieldValue::query()->where('silabo_id', $syllabus->id)
            ->where('definicion_campo_id', FieldDefinition::query()->get()->firstWhere('clave', $key)->id)
            ->update(['valor' => FreeText::fromText($text)]);
        $answer($kept, 'metodologia', 'Proyectos.');
        $answer($losing, 'tecnicas_ensenanza', 'Debates guiados.');
        $answer($approved, 'metodologia', 'Laboratorio.');
        $this->actingAsRole('admin@silabos.test');
        $this->post(route('api.v1.admin.processes.transition', [$process, 'pausar']))->assertSuccessful();

        return [$process->fresh(), $kept->fresh(), $losing->fresh(), $approved->fresh()];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    private function teacherText(array $ids): array
    {
        $texts = [];
        foreach ($ids as $id) {
            $texts[$id] = [];
            foreach (FieldValue::query()->where('silabo_id', $id)->get() as $value) {
                foreach (['Proyectos.', 'Debates guiados.', 'Laboratorio.'] as $text) {
                    if (str_contains((string) json_encode($value->valor, JSON_UNESCAPED_UNICODE), $text)) {
                        $texts[$id][] = $text;
                    }
                }
            }
        }

        return $texts;
    }

    private function actingAsRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $assignment = $user->roleAssignments()->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $assignment->id]);
    }
}
