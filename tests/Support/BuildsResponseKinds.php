<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Testing\TestResponse;

trait BuildsResponseKinds
{
    protected FieldDefinition $responseField;

    protected function asUser(string $email): static
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();

        return $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    protected function response(string $kind, array $options = [], mixed $value = null): array
    {
        $attrs = ['kind' => $kind, 'options' => array_map(fn (int $index, string $title): array => ['const' => 'o'.($index + 1), 'title' => $title], array_keys($options), $options)];

        return ['type' => 'response', 'attrs' => $value === null ? $attrs : [...$attrs, 'value' => $value]];
    }

    /**
     * Campo del docente «metodologia»: un texto fijo, un espacio de respuesta y una tabla que crece por filas.
     *
     * @param  array<string, mixed>|null  $slot
     */
    protected function templateWithResponseField(?array $slot = null, string $key = 'metodologia'): SyllabusTemplate
    {
        $this->asUser('admin@silabos.test')->postJson(route('api.v1.admin.template.store'))->assertCreated();
        $template = SyllabusTemplate::institutional();
        $this->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->responseField = FieldDefinition::query()->where('clave', $key)->firstOrFail();
        $cell = fn (string $type, string $text = ''): array => ['type' => $type, 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [
            $text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
        $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$template, $this->responseField]), ['content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Estrategias de la asignatura']]],
            $slot === null ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [$slot]],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [$cell('tableHeader', 'Técnica'), $cell('tableHeader', 'Horas')]],
                ['type' => 'tableRow', 'content' => [$cell('tableCell'), $cell('tableCell')]],
            ]],
        ]]])->assertOk();
        $this->responseField->refresh();

        return $template;
    }

    /**
     * La plantilla del campo con respuestas puestas en el espacio suelto y en las dos celdas de la tabla.
     *
     * @param  array<string, mixed>|null  $slot
     * @param  array<string, mixed>|null  $technique
     * @param  array<string, mixed>|null  $hours
     * @param  array<string, mixed>|null  $base
     * @return array<string, mixed>
     */
    protected function withResponses(?array $slot = null, ?array $technique = null, ?array $hours = null, ?array $base = null): array
    {
        $document = $base ?? FieldResponse::template(FieldDocument::normalize($this->responseField->contenido));
        if ($slot !== null) {
            $document['content'][1]['content'] = [$slot];
        }
        foreach ([$technique, $hours] as $column => $node) {
            if ($node !== null) {
                $document['content'][2]['content'][1]['content'][$column]['content'][0]['content'] = [$node];
            }
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @param  array<string, mixed>  $extra
     */
    protected function saveSetup(?array $document, array $extra = [], ?Subject $subject = null): TestResponse
    {
        return $this->asUser('coordinador@silabos.test')->putJson(
            route('api.v1.academic.subjects.profile.update', $subject ?? Subject::query()->firstOrFail()),
            ['values' => [$this->responseField->id => $document], ...$extra],
        );
    }
}
