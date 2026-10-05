<?php

namespace Tests;

use App\Models\User;
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

    /** Llena la ficha de todas las asignaturas y la formación de los docentes, que los sílabos nuevos heredan. */
    protected function fillSubjectProfiles(): void
    {
        User::query()->update(['formacion_experiencia_usuario' => 'Formación de prueba del docente.']);
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
