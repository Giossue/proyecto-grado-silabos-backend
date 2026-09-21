<?php

namespace Database\Seeders;

use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Database\Seeder;

class SyllabusTemplateSeeder extends Seeder
{
    public function run(SyllabusTemplateProvisioner $provisioner): void
    {
        if (SyllabusTemplate::query()->exists()) {
            return;
        }

        $provisioner->create();
    }
}
