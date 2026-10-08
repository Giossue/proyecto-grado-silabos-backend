<?php

namespace Tests\Feature\Syllabus;

use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class CloseExpiredProcessesTest extends TestCase
{
    use CreatesSyllabusProcess, RefreshDatabase;

    public function test_the_command_closes_a_process_only_after_its_delivery_date_passes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $template = SyllabusTemplate::query()->create(['nombre' => 'Plantilla vencimiento', 'activo' => true]);
        $process = $this->openSyllabusProcess($template->id, now()->subWeek(), now()->addDay());

        $this->artisan('procesos:finalizar-vencidos')->assertSuccessful();
        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()->estado);

        $process->update(['estado' => SyllabusProcess::STATE_PAUSED, 'entrega_en' => now()->subDay()]);
        $this->artisan('procesos:finalizar-vencidos')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()->estado);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'proceso_silabos.cerrado',
            'recurso_id' => $process->id,
        ]);
    }
}
