<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldLimitTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const INVALID = 'El límite de caracteres debe ser un número entero entre 1 y 50 000.';

    private const ONLY_CAREER = 'Solo un campo editable (Carrera) puede tener límite de caracteres.';

    private SyllabusTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
        $this->postJson(route('api.v1.admin.template.store'))->assertCreated();
        $this->template = SyllabusTemplate::institutional();
        $this->postJson(route('api.v1.admin.template.format', $this->template))->assertOk();
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        $json = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        return ['version' => 1, 'title' => $json['document']['title']['text'], 'sections' => $json['sections']];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function withField(array $draft, string $key, array $changes): array
    {
        foreach ($draft['sections'] as $sectionIndex => $section) {
            foreach ($section['fields'] as $fieldIndex => $field) {
                if ($field['key'] === $key) {
                    $draft['sections'][$sectionIndex]['fields'][$fieldIndex] = [...$field, ...$changes];
                }
            }
        }

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function without(array $draft, string $key, string $property): array
    {
        foreach ($draft['sections'] as $sectionIndex => $section) {
            foreach ($section['fields'] as $fieldIndex => $field) {
                if ($field['key'] === $key) {
                    unset($draft['sections'][$sectionIndex]['fields'][$fieldIndex][$property]);
                }
            }
        }

        return $draft;
    }

    private function path(string $key): string
    {
        foreach ($this->draft()['sections'] as $sectionIndex => $section) {
            foreach ($section['fields'] as $fieldIndex => $field) {
                if ($field['key'] === $key) {
                    return "sections.{$sectionIndex}.fields.{$fieldIndex}";
                }
            }
        }

        return '';
    }

    /** @param array<string, mixed> $draft
     * @return list<array{path: string, message: string, blocking: bool}>
     */
    private function problems(array $draft): array
    {
        return $this->postJson(route('api.v1.admin.template.draft.check', $this->template), $draft)->assertOk()->json('problems');
    }

    /** @return array<string, array<string, mixed>> */
    private function adminFields(): array
    {
        return collect($this->getJson(route('api.v1.admin.template.index'))->assertOk()->json('template.sections'))
            ->flatMap(fn (array $section): array => $section['fields'])->keyBy('key')->all();
    }

    /** @return array<string, mixed> */
    private function contents(): array
    {
        $contents = FieldDefinition::query()->get()->mapWithKeys(fn (FieldDefinition $field): array => [$field->clave => $field->getRawOriginal('contenido_definicion_campo')])->all();
        ksort($contents);

        return $contents;
    }

    public function test_the_administrator_sets_and_removes_a_limit_on_an_editable_field(): void
    {
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->withField($this->draft(), 'descripcion', ['max_length' => 500]))->assertOk();

        $this->assertSame(['descripcion' => 500], $this->template->fresh()->definition()->fieldLimits());
        $fields = $this->adminFields();
        $this->assertSame(500, $fields['descripcion']['max_length']);
        $this->assertArrayNotHasKey('max_length', $fields['metodologia']);

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->withField($this->draft(), 'descripcion', ['max_length' => null]))->assertOk();

        $this->assertSame([], $this->template->fresh()->definition()->fieldLimits());
        $this->assertArrayNotHasKey('field_limits', $this->template->fresh()->definition()->toArray());
        $this->assertArrayNotHasKey('max_length', $this->adminFields()['descripcion']);
    }

    public function test_a_limit_on_a_not_editable_field_or_out_of_range_is_rejected_and_shown_live(): void
    {
        $institutional = $this->withField($this->draft(), 'conducta_etica', ['max_length' => 500]);
        $this->assertContains(['path' => $this->path('conducta_etica').'.max_length', 'message' => self::ONLY_CAREER, 'blocking' => true], $this->problems($institutional));
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $institutional)->assertUnprocessable()
            ->assertJsonValidationErrors([$this->path('conducta_etica').'.max_length' => self::ONLY_CAREER]);

        foreach ([0, 50001, 12.5, '500', true] as $value) {
            $invalid = $this->withField($this->draft(), 'descripcion', ['max_length' => $value]);
            $this->assertContains(['path' => $this->path('descripcion').'.max_length', 'message' => self::INVALID, 'blocking' => true], $this->problems($invalid));
            $this->putJson(route('api.v1.admin.template.json.import', $this->template), $invalid)->assertUnprocessable()
                ->assertJsonValidationErrors([$this->path('descripcion').'.max_length' => self::INVALID]);
        }

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->withField($this->draft(), 'descripcion', ['max_length' => 50000]))->assertOk();
        $this->assertSame(['descripcion' => 50000], $this->template->fresh()->definition()->fieldLimits());
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->withField($this->draft(), 'descripcion', ['max_length' => 1]))->assertOk();
        $this->assertSame(['descripcion' => 1], $this->template->fresh()->definition()->fieldLimits());
    }

    public function test_the_limit_travels_in_the_json_export_and_import(): void
    {
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->withField($this->draft(), 'objetivo_general', ['max_length' => 1200]))->assertOk();

        $export = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();
        $fields = collect($export['sections'])->flatMap(fn (array $section): array => $section['fields'])->keyBy('key');
        $this->assertSame(1200, $fields['objetivo_general']['max_length']);
        $this->assertArrayNotHasKey('max_length', $fields['descripcion']);

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->without($this->draft(), 'objetivo_general', 'max_length'))->assertOk();
        $this->assertSame([], $this->template->fresh()->definition()->fieldLimits());

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$export, 'version' => 2])->assertOk();
        $this->assertSame(['objetivo_general' => 1200], $this->template->fresh()->definition()->fieldLimits());
        $this->assertSame($export, $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json());
    }

    public function test_the_draft_preview_carries_the_limit_for_the_teacher_view(): void
    {
        $preview = $this->postJson(route('api.v1.admin.template.draft.preview', $this->template), $this->withField($this->draft(), 'descripcion', ['max_length' => 300]))->assertOk()->json();

        $fields = collect($preview['sections'])->flatMap(fn (array $section): array => $section['fields'])->keyBy('key');
        $this->assertSame(300, $fields['descripcion']['max_length']);
        $this->assertArrayNotHasKey('max_length', $fields['metodologia']);
        $sheets = collect($preview['sheets'])->flatMap(fn (array $section): array => $section['fields'])->keyBy('key');
        $this->assertSame(300, $sheets['descripcion']['max_length']);
        $this->assertSame([], $this->template->fresh()->definition()->fieldLimits());
    }

    public function test_a_template_without_limits_saves_exactly_as_before(): void
    {
        $definition = $this->template->fresh()->getRawOriginal('definicion');
        $contents = $this->contents();
        $export = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->draft())->assertOk();

        $this->assertSame($definition, $this->template->fresh()->getRawOriginal('definicion'));
        $this->assertSame($contents, $this->contents());
        $this->assertSame($export, $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json());
        $this->assertStringNotContainsString('max_length', (string) json_encode($export));
        $this->assertStringNotContainsString('field_limits', (string) $definition);
    }
}
