<?php

namespace Tests\Feature\Configuration;

use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Domain\FieldDocument;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BibliographyIsATemplateBlockMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Autor', 'Título', 'Año', 'Ciudad', 'Editorial', 'ISBN', 'Código'];

    public function test_the_official_tables_become_the_block_and_keep_what_the_teacher_wrote(): void
    {
        [$basic, $complementary, $current] = $this->storeOldTables();
        $subject = Subject::query()->firstOrFail();
        $owner = ['asignatura_id' => $subject->id];
        $this->value($owner, $basic, $this->table([
            ['Sapag Chain, Nassir', 'Proyectos de inversión', '2011', 'Santiago de Chile', 'Pearson', '9789563431063', '658.404'],
            ['', '', '', '', '', '', ''],
            ['Torrent, Mateo', 'Zootecnia básica', '1982', 'Madrid', 'Aedos', '8470032542', ''],
        ]));
        $this->value($owner, $complementary, $this->table([['', '', '', '', '', '', '']]));

        $this->migration()->up();

        $definition = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
        $this->assertSame($current['blocks']['bibliografia'], $definition['blocks']['bibliografia']);
        foreach ([$basic, $complementary] as $id) {
            $stored = json_decode((string) DB::table('definiciones_campo')->where('definicion_campo_id', $id)->value('contenido_definicion_campo'), true);
            $this->assertSame('bibliografia', $stored['content'][0]['attrs']['kind']);
            $this->assertEquals($stored, FieldDocument::normalize($stored));
        }
        $books = fn (string $id): array => json_decode((string) DB::table('valores_campo')->where('definicion_campo_id', $id)->value('valor_campo'), true)['content'][0]['attrs']['value']['libros'];
        $this->assertEquals([
            ['autor' => 'Sapag Chain, Nassir', 'titulo' => 'Proyectos de inversión', 'anio' => '2011', 'ciudad' => 'Santiago de Chile', 'editorial' => 'Pearson', 'isbn' => '9789563431063', 'codigo' => '658.404'],
            ['autor' => 'Torrent, Mateo', 'titulo' => 'Zootecnia básica', 'anio' => '1982', 'ciudad' => 'Madrid', 'editorial' => 'Aedos', 'isbn' => '8470032542', 'codigo' => ''],
        ], $books($basic));
        $this->assertEquals([array_fill_keys(['autor', 'titulo', 'anio', 'ciudad', 'editorial', 'isbn', 'codigo'], '')], $books($complementary));
    }

    public function test_a_table_changed_by_administration_is_left_alone(): void
    {
        [$basic] = $this->storeOldTables();
        $custom = $this->table([['', '', '', '', '', '', '']], ['Autor/es', 'Título', 'Año', 'Ciudad', 'Editorial', 'ISBN', 'Código']);
        DB::table('definiciones_campo')->where('definicion_campo_id', $basic)->update(['contenido_definicion_campo' => json_encode($custom)]);

        $this->migration()->up();

        $this->assertEquals($custom, json_decode((string) DB::table('definiciones_campo')->where('definicion_campo_id', $basic)->value('contenido_definicion_campo'), true));
    }

    public function test_it_does_not_mark_the_template_as_modified(): void
    {
        $this->storeOldTables();
        $before = DB::table('plantilla_silabo')->value('plantilla_modificada_en');

        $this->migration()->up();

        $this->assertEquals($before, DB::table('plantilla_silabo')->value('plantilla_modificada_en'));
    }

    /** @return array{string, string, array<string, mixed>} */
    private function storeOldTables(): array
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $definition = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
        $current = $definition;
        unset($definition['blocks']['bibliografia']);
        DB::table('plantilla_silabo')->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE)]);
        $ids = [];
        foreach (['bibliografia', 'bibliografia_complementaria'] as $key) {
            $ids[$key] = (string) DB::table('definiciones_campo')
                ->join('campos_plantilla', 'campos_plantilla.campo_plantilla_id', '=', 'definiciones_campo.campo_plantilla_id')
                ->where('campos_plantilla.clave_campo_plantilla', $key)->value('definiciones_campo.definicion_campo_id');
            DB::table('definiciones_campo')->where('definicion_campo_id', $ids[$key])
                ->update(['contenido_definicion_campo' => json_encode($this->table([['', '', '', '', '', '', '']]))]);
        }

        return [$ids['bibliografia'], $ids['bibliografia_complementaria'], $current];
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    private function table(array $rows, array $headers = self::HEADERS): array
    {
        $cell = fn (string $type, string $text): array => ['type' => $type, 'content' => [['type' => 'paragraph', 'content' => $text === '' ? [] : [['type' => 'text', 'text' => $text]]]]];

        return ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => array_map(fn (string $text): array => $cell('tableHeader', $text), $headers)],
            ...array_map(fn (array $row): array => ['type' => 'tableRow', 'content' => array_map(fn (string $text): array => $cell('tableCell', $text), $row)], $rows),
        ]]]];
    }

    /**
     * @param  array<string, string>  $owner
     * @param  array<string, mixed>  $document
     */
    private function value(array $owner, string $fieldId, array $document): void
    {
        DB::table('valores_campo')->insert([
            'valor_campo_id' => (string) Str::uuid(),
            ...$owner,
            'definicion_campo_id' => $fieldId,
            'valor_campo' => json_encode($document),
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_31_000252_bibliography_is_a_template_block.php');
    }
}
