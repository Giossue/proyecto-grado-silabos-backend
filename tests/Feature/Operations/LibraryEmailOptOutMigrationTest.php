<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Operations\Application\EmailNoticeCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryEmailOptOutMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_librarian_who_turned_off_the_review_email_keeps_it_off_after_the_new_order(): void
    {
        $this->seed(DatabaseSeeder::class);
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        $librarian->forceFill(['preferencias_correo' => ['coordinacion.aprobado' => false, 'proceso.reanudado' => true]])->save();
        $catalog = app(EmailNoticeCatalog::class);

        $this->migration()->up();

        $this->assertSame(['proceso.reanudado' => true, 'silabo.enviado' => false], $this->preferences($librarian));
        $this->assertFalse($catalog->wants($librarian->fresh(), 'silabo.enviado'));
        $this->assertFalse(collect($catalog->optionsFor($librarian->fresh()))->firstWhere('type', 'silabo.enviado')['enabled']);

        $this->migration()->down();

        $this->assertSame(['coordinacion.aprobado' => false, 'proceso.reanudado' => true], $this->preferences($librarian));
    }

    /** @return array<string, bool> */
    private function preferences(User $user): array
    {
        $preferences = $user->fresh()->preferencias_correo ?? [];
        ksort($preferences);

        return $preferences;
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_11_000271_library_review_email_keeps_its_switch.php');
    }
}
