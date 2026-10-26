<?php

namespace Tests;

use App\Models\User;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
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

    /** @return array<string, mixed> */
    protected function filledTemplateValue(FieldDefinition $field, string $text): array
    {
        $fill = function (array $node) use (&$fill, $text): array {
            if (FieldResponse::blank($node)) {
                return [...$node, 'content' => ($node['type'] === 'paragraph' ? FreeText::fromText($text)['content'][0]['content'] : FreeText::fromText($text)['content'])];
            }
            if (isset($node['content']) && $node['type'] !== 'tableHeader') {
                $node['content'] = array_map($fill, $node['content']);
            }

            return $node;
        };

        return $fill(FieldResponse::template($field->contenido));
    }

    protected function fillSubjectProfiles(): void
    {
        User::query()->update(['formacion_experiencia_usuario' => 'Formación de prueba del docente.']);
        $fields = app(SubjectProfileFields::class)->fields();
        foreach (Subject::query()->get() as $subject) {
            foreach ($fields as $field) {
                FieldValue::query()->updateOrCreate(
                    ['asignatura_id' => $subject->id, 'definicion_campo_id' => $field->id],
                    ['valor' => $this->filledTemplateValue($field, "Ficha de prueba: {$field->clave}")],
                );
            }
        }
    }
}
