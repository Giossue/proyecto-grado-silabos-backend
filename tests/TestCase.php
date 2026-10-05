<?php

namespace Tests;

use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sanctum.stateful' => ['localhost:5173'],
            'sanctum.middleware.authenticate_session' => null,
        ]);
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /** Llena la ficha de todas las asignaturas: un texto por cada campo «desde la ficha» de la plantilla. */
    protected function fillSubjectProfiles(): void
    {
        $fields = app(SubjectProfileFields::class)->fields();
        foreach (Subject::query()->get() as $subject) {
            foreach ($fields as $field) {
                FieldValue::query()->updateOrCreate(
                    ['asignatura_id' => $subject->id, 'definicion_campo_id' => $field->id],
                    ['valor' => FreeText::fromText("Ficha de prueba: {$field->clave}")],
                );
            }
        }
    }
}
