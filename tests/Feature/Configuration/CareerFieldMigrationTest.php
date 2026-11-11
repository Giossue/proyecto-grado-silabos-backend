<?php

namespace Tests\Feature\Configuration;

use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Application\SnapshotDocuments;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsResponseKinds;
use Tests\TestCase;

class CareerFieldMigrationTest extends TestCase
{
    use BuildsResponseKinds, RefreshDatabase;

    public function test_teacher_and_coordination_fields_become_career_fields_and_keep_what_was_written(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->templateWithResponseField();
        $subject = Subject::query()->firstOrFail();
        $this->fillSubjectProfiles();
        $this->saveSetup($this->withResponses($this->response('texto')))->assertOk();
        $this->migration()->down();
        $keys = fn (array $keys): array => FieldDefinition::query()->get()->filter(fn (FieldDefinition $field): bool => in_array($field->clave, $keys, true))->pluck('id')->all();
        DB::table('definiciones_campo')->whereIn('definicion_campo_id', $keys(self::CAREER_PROJECT_KEYS))->update(['quien_llena_definicion_campo' => 'coordinacion']);
        $definition = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
        $definition['presets'][0]['quien_llena'] = 'docente';
        DB::table('plantilla_silabo')->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE)]);
        $this->assertSame(['coordinacion', 'docente', 'institucional'], DB::table('definiciones_campo')->distinct()->orderBy('quien_llena_definicion_campo')->pluck('quien_llena_definicion_campo')->all());
        $rows = DB::table('valores_campo')->whereNotNull('asignatura_id')->orderBy('valor_campo_id')->pluck('valor_campo', 'valor_campo_id')->all();
        $snapshot = ['schema_version' => 9, 'sections' => [['key' => 'descripcion', 'title' => 'Descripción', 'fields' => [
            ['key' => 'descripcion', 'label' => '', 'quien_llena' => 'coordinacion', 'value' => FreeText::fromText('Texto enviado'), 'document' => FreeText::fromText('Texto enviado')],
            ['key' => 'metodologia', 'label' => '', 'quien_llena' => 'docente', 'value' => FreeText::fromText('Respuesta enviada'), 'document' => FreeText::fromText('Respuesta enviada')],
        ]]]];

        DB::table('plantilla_silabo')->update(['plantilla_modificada_en' => '2026-01-02 03:04:05+00']);
        $date = DB::table('plantilla_silabo')->value('plantilla_modificada_en');

        $this->migration()->up();

        $this->assertSame($date, DB::table('plantilla_silabo')->value('plantilla_modificada_en'), 'Unir los responsables no cuenta como edición de la plantilla.');
        $this->assertSame(['carrera', 'institucional'], DB::table('definiciones_campo')->distinct()->orderBy('quien_llena_definicion_campo')->pluck('quien_llena_definicion_campo')->all());
        $this->assertSame($rows, DB::table('valores_campo')->whereNotNull('asignatura_id')->orderBy('valor_campo_id')->pluck('valor_campo', 'valor_campo_id')->all());
        $this->assertSame('carrera', json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true)['presets'][0]['quien_llena']);
        $this->assertStringContainsString("'carrera'", (string) DB::scalar("SELECT column_default FROM information_schema.columns WHERE table_name = 'definiciones_campo' AND column_name = 'quien_llena_definicion_campo'"));

        $fields = FieldDefinition::query()->get()->keyBy('clave');
        $templates = app(SubjectProfileFields::class)->templates($subject->id);
        $this->assertFalse(SubjectProfileFields::teacherEditable($fields['descripcion'], $templates));
        $this->assertTrue(SubjectProfileFields::teacherEditable($fields['tecnicas_ensenanza'], $templates));
        $this->assertTrue(SubjectProfileFields::teacherEditable($fields['metodologia'], $templates));
        $this->assertSame('texto', $templates[$fields['metodologia']->id]['content'][1]['content'][0]['attrs']['kind']);
        $present = SnapshotDocuments::present($snapshot);
        $this->assertSame(['coordinacion', 'docente'], array_column($present['sections'][0]['fields'], 'quien_llena'));
        $this->assertSame('Texto enviado', FreeText::plainText($present['sections'][0]['fields'][0]['document']));

        $this->expectException(QueryException::class);
        DB::table('definiciones_campo')->limit(1)->update(['quien_llena_definicion_campo' => 'docente']);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_10_000268_career_field_replaces_teacher_and_coordination.php');
    }
}
