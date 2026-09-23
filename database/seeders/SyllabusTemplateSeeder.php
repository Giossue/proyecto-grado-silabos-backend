<?php

namespace Database\Seeders;

use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Database\Seeder;

class SyllabusTemplateSeeder extends Seeder
{
    public function run(SyllabusTemplateProvisioner $provisioner): void
    {
        $template = SyllabusTemplate::query()->first();

        if ($template === null) {
            $provisioner->createWithOfficialFormat();

            return;
        }

        if ($template->hasStructure()) {
            return;
        }

        $provisioner->format($template);
    }
}
