<?php

namespace Tests\Feature\Configuration;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnitsNeedAWeekMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stored_template_requires_a_week_per_unit_without_touching_the_other_rules(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $definition = $this->stored();
        $definition['blocks']['unidades']['rules'] = array_values(array_filter($definition['blocks']['unidades']['rules'], fn (array $rule): bool => $rule['code'] !== 'unidades_sin_semanas'));
        $definition['blocks']['unidades']['rules'][0]['message'] = 'Mensaje propio de la plantilla.';
        DB::table('plantilla_silabo')->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE)]);

        $this->migration()->up();
        $this->migration()->up();

        $rules = $this->stored()['blocks']['unidades']['rules'];
        $codes = array_column($rules, 'code');
        $this->assertSame(1, array_count_values($codes)['unidades_sin_semanas']);
        $this->assertSame(array_search('unidades_sin_nombre', $codes, true) + 1, array_search('unidades_sin_semanas', $codes, true));
        $this->assertSame('Mensaje propio de la plantilla.', $rules[0]['message']);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_08_000267_units_need_a_week.php');
    }
}
