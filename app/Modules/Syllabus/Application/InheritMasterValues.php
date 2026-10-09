<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Support\Collection;

class InheritMasterValues
{
    public function __construct(private readonly SubjectProfileFields $profiles) {}

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     */
    public function execute(Syllabus $syllabus, Collection $fields, Parallel $parallel): void
    {
        $parallel->loadMissing('subject');
        $profile = $this->profiles->values($parallel->subject->id);
        foreach ($fields as $field) {
            if ($field->quien_llena === FieldDefinition::INSTITUTIONAL) {
                continue;
            }
            FieldValue::query()->create([
                'silabo_id' => $syllabus->id,
                'definicion_campo_id' => $field->id,
                'valor' => $field->fromProfile() ? ($profile[$field->id] ?? $field->contenido) : $field->contenido,
            ]);
        }
    }
}
