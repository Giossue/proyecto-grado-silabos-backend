<?php

namespace Tests\Feature\Configuration;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnitsAndWeeksFollowThePeriodMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stored_units_admit_one_to_eight_and_add_up_the_weeks_of_the_period(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $definition = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
        $definition['blocks']['unidades']['value']['units']['minItems'] = 4;
        $definition['blocks']['unidades']['value']['units']['maxItems'] = 4;
        unset($definition['variables']['semanas_periodo']);
        DB::table('plantilla_silabo')->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE)]);

        $this->migration()->up();

        $stored = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
        $this->assertSame([1, 8], [$stored['blocks']['unidades']['value']['units']['minItems'], $stored['blocks']['unidades']['value']['units']['maxItems']]);
        $this->assertSame('identification.teaching_weeks', $stored['variables']['semanas_periodo']['source']);
        $weeks = collect($stored['blocks']['unidades']['rules'])->firstWhere('code', 'semanas_unidades_incompletas');
        $this->assertSame(['variable' => 'semanas_periodo'], $weeks['checks'][0]['equals']);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_27_000244_units_and_weeks_follow_the_period.php');
    }
}
