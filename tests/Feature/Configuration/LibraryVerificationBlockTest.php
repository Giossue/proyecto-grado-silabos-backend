<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\FixedContent;
use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LibraryVerificationBlockTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURES = [
        ['Elaborado por', 'Revisado y aprobado por'],
        ['Firma: {firma_docente}', 'Firma: {firma_coordinador}'],
        ['{nombre_docente} Docente – Investigador/a', '{nombre_coordinador} Coordinación de Carrera'],
        ['Fecha de entrega: {fecha_entrega}', 'Fecha de revisión: {fecha_revision}'],
    ];

    private const LIBRARY = [
        ['Bibliografía verificada por'],
        ['Firma: {firma_bibliotecario}'],
        ['{nombre_bibliotecario} Biblioteca'],
        ['Fecha de verificación: {fecha_verificacion}'],
    ];

    public function test_the_library_column_is_a_one_column_table_below_the_complementary_bibliography(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $template = SyllabusTemplate::query()->firstOrFail();
        $fields = $template->fields()->get()->keyBy('clave');

        $section = $template->sections->firstWhere('clave', 'bibliografia');
        $this->assertSame(['bibliografia', 'bibliografia_complementaria', 'verificacion_bibliografia'], $section->blocks()->pluck('clave')->all());
        $this->assertSame('', $section->blocks()->firstWhere('clave', 'verificacion_bibliografia')->titulo);
        $this->assertSame(FieldDefinition::INSTITUTIONAL, $fields['verificacion_bibliografia']->quien_llena);
        $this->assertSame(self::SIGNATURES, $this->cells($fields['firmas']->contenido));
        $this->assertSame(self::LIBRARY, $this->cells($fields['verificacion_bibliografia']->contenido));
        $this->assertSame($this->headerStyle($fields['firmas']->contenido), $this->headerStyle($fields['verificacion_bibliografia']->contenido));
        $this->assertSame('#C3D4EA', $this->headerStyle($fields['verificacion_bibliografia']->contenido)['backgroundColor']);

        $presets = collect(OfficialTemplate::definition()->presets())->keyBy('key');
        $this->assertSame(['identificacion', 'planificacion', 'bibliografia_basica', 'bibliografia_complementaria', 'verificacion_bibliografia', 'firmas'], $presets->keys()->all());
        $this->assertSame(['Bibliografía verificada por', 'verificacion_bibliografia', 'institucional'], [$presets['verificacion_bibliografia']['label'], $presets['verificacion_bibliografia']['field'], $presets['verificacion_bibliografia']['quien_llena']]);
        $this->assertSame(self::LIBRARY, $this->cells($presets['verificacion_bibliografia']['content']));
        $this->assertSame(self::SIGNATURES, $this->cells($presets['firmas']['content']));
    }

    public function test_the_default_and_fixed_copies_of_the_signature_table_drop_the_library_column(): void
    {
        $this->assertSame([
            ['Elaborado por', 'Revisado y aprobado por'],
            ['{firma_docente}', '{firma_coordinador}'],
            ['{nombre_docente}', '{nombre_coordinador}'],
            ['Docente de la asignatura', 'Coordinación de carrera'],
            ['Fecha de entrega: {fecha_entrega}', 'Fecha de aprobación: {fecha_revision}'],
        ], $this->cells(TemplateDocumentDefaults::signatures()));
        $this->assertSame([
            ['Bibliografía verificada por'],
            ['{firma_bibliotecario}'],
            ['{nombre_bibliotecario}'],
            ['Biblioteca'],
            ['Fecha de verificación: {fecha_verificacion}'],
        ], $this->cells(TemplateDocumentDefaults::libraryVerification()));
        $this->assertSame('#C3D4EA', $this->headerStyle(TemplateDocumentDefaults::libraryVerification())['backgroundColor']);

        $this->assertSame([
            ['Firma:', 'Firma:'],
            ['Docente – Investigador/a', 'Coordinación de Carrera'],
            ['Fecha de entrega:', 'Fecha de revisión:'],
        ], $this->cells(FixedContent::official('firmas')));
        $this->assertSame([
            ['Bibliografía verificada por'],
            ['Firma:'],
            ['Biblioteca'],
            ['Fecha de verificación:'],
        ], $this->cells(FixedContent::official('verificacion_bibliografia')));
    }

    public function test_the_migration_moves_the_library_column_of_the_stored_template_without_counting_as_an_edit(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $official = $this->stored();
        DB::table('plantilla_silabo')->update(['plantilla_modificada_en' => '2026-01-02 03:04:05+00']);
        $date = DB::table('plantilla_silabo')->value('plantilla_modificada_en');

        $this->migration()->down();

        $old = $this->stored();
        $this->assertSame($date, DB::table('plantilla_silabo')->value('plantilla_modificada_en'), 'Devolver la columna no cuenta como edición de la plantilla.');
        $this->assertArrayNotHasKey('verificacion_bibliografia', $old['fields']);
        $this->assertSame(['bibliografia', 'bibliografia_complementaria'], $old['bibliography']);
        $threeColumns = array_map(fn (array $row, array $library): array => [...$row, ...$library], self::SIGNATURES, self::LIBRARY);
        $this->assertSame($threeColumns, $this->cells($old['fields']['firmas']));
        $this->assertSame(['identificacion', 'planificacion', 'bibliografia_basica', 'bibliografia_complementaria', 'firmas'], array_keys($old['presets']));
        $this->assertSame($threeColumns, $this->cells($old['presets']['firmas']['content']));

        $this->migration()->up();

        $this->assertSame($date, DB::table('plantilla_silabo')->value('plantilla_modificada_en'), 'Mover la columna no cuenta como edición de la plantilla.');
        $this->assertEquals($official, $this->stored());

        $this->migration()->up();
        $this->assertEquals($official, $this->stored());
    }

    public function test_the_migration_keeps_what_administration_wrote_in_the_library_column(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $this->migration()->down();
        $id = (string) FieldDefinition::query()->get()->firstWhere('clave', 'firmas')->id;
        $content = (string) DB::table('definiciones_campo')->where('definicion_campo_id', $id)->value('contenido_definicion_campo');
        DB::table('definiciones_campo')->where('definicion_campo_id', $id)->update(['contenido_definicion_campo' => str_replace('"text": "Biblioteca"', '"text": "Biblioteca central"', $content)]);

        $this->migration()->up();

        $fields = $this->stored()['fields'];
        $this->assertSame('{nombre_bibliotecario} Biblioteca central', $this->cells($fields['verificacion_bibliografia'])[2][0]);
        $this->assertSame(self::SIGNATURES, $this->cells($fields['firmas']));
    }

    /** @return array{fields: array<string, array<string, mixed>>, responsibles: array<string, string>, bibliography: list<string>, presets: array<string, array<string, mixed>>} */
    private function stored(): array
    {
        $template = SyllabusTemplate::query()->firstOrFail();
        $fields = $template->fields()->where('vigente_en_plantilla', true)->get()->keyBy('clave');
        $definition = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);

        return [
            'fields' => $fields->map(fn (FieldDefinition $field): array => $field->contenido)->all(),
            'responsibles' => $fields->map(fn (FieldDefinition $field): string => $field->quien_llena)->all(),
            'bibliography' => $template->sections->firstWhere('clave', 'bibliografia')->blocks()->pluck('clave')->all(),
            'presets' => collect($definition['presets'])->keyBy('key')->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<list<string>>
     */
    private function cells(array $document): array
    {
        $table = collect($document['content'])->firstWhere('type', 'table');

        $cell = fn (array $cell): string => trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_map(fn (array $paragraph): string => $this->text($paragraph), $cell['content'] ?? []))));

        return array_map(fn (array $row): array => array_map($cell, $row['content']), $table['content']);
    }

    /** @param  array<string, mixed>  $node */
    private function text(array $node): string
    {
        return match ($node['type'] ?? null) {
            'text' => (string) $node['text'],
            'variable' => '{'.$node['attrs']['id'].'}',
            default => implode('', array_map(fn (array $child): string => $this->text($child), $node['content'] ?? [])),
        };
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function headerStyle(array $document): array
    {
        $table = collect($document['content'])->firstWhere('type', 'table');

        return ['type' => $table['content'][0]['content'][0]['type'], ...$table['content'][0]['content'][0]['attrs']];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_11_000270_library_verification_below_the_bibliography.php');
    }
}
