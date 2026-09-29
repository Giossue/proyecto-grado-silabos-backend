<?php

namespace Tests\Feature\AiAssistance;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministrationDecidesAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordination_cannot_override_the_ai_setting_of_a_field(): void
    {
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $role = $coordinator->roleAssignments()->firstOrFail();
        $request = $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $role->id])
            ->withHeader('Origin', 'http://localhost:5173');

        $request->getJson('/api/v1/coordination/ai-configuration')->assertNotFound();
        $request->putJson('/api/v1/coordination/ai-configuration', ['preferences' => []])->assertNotFound();
        $request->deleteJson('/api/v1/coordination/ai-configuration')->assertNotFound();
    }
}
