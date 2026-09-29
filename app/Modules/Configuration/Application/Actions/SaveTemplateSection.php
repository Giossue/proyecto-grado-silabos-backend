<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveTemplateSection
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(string $templateId, array $data, User $actor, Request $request): TemplateSection
    {
        $activeRole = $this->roles->resolve($request);
        // Con el proceso institucional abierto, el formato está en uso: se pausa antes.
        $this->locks->assertTemplateEditable();

        return $this->work->replace($request, function () use ($activeRole, $actor, $data, $request, $templateId): TemplateSection {
            $template = SyllabusTemplate::query()->whereKey($templateId)->lockForUpdate()->firstOrFail();
            // Una sección nueva cambia el formato que los docentes están llenando.

            $sections = $template->sections()->sortBy('posicion')->values();
            $position = min(
                max(1, ((int) ($data['position'] ?? $sections->count() + 1))),
                $sections->count() + 1,
            );
            $sectionId = (string) Str::uuid();
            $blocks = [];
            $fieldDefinitions = [];

            // Formas de una parte nueva (I-156): Texto libre o Contenido de la plantilla.
            foreach ($this->fields($data) as $fieldPosition => $fieldData) {
                $blockId = (string) Str::uuid();
                $fieldKey = $this->stringValue($fieldData, 'key');
                $fieldLabel = trim((string) ($fieldData['label'] ?? ''));
                $freeText = $this->stringValue($fieldData, 'content_type') === 'free_text';
                $label = $fieldLabel !== '' ? $fieldLabel : $this->stringValue($data, 'title');
                $blocks[] = [
                    'id' => $blockId,
                    'clave' => $fieldKey,
                    'titulo' => $fieldLabel,
                    'configuracion' => ['content_type' => 'text', 'document' => $freeText
                        ? TemplateDocumentDefaults::freeTextSheet($fieldKey, $label)
                        : TemplateDocumentDefaults::blankSheet()],
                    'posicion' => $fieldPosition + 1,
                ];
                // El Contenido de la plantilla no nace con preguntas del docente.
                if (! $freeText) {
                    continue;
                }
                $fieldDefinitions[] = [
                    'plantilla_id' => $template->id,
                    'bloque_plantilla_id' => $blockId,
                    'clave' => $fieldKey,
                    'etiqueta' => $label,
                    'tipo' => 'texto_libre',
                    'ayuda' => $fieldData['help'] ?? null,
                    'heredado' => false,
                    'editable_docente' => true,
                    'ia_habilitada' => (bool) ($fieldData['ai_enabled'] ?? false),
                    'posicion' => 1,
                ];
            }

            $orderedSections = $sections
                ->map(fn (TemplateSection $existingSection): array => $existingSection->toDocument())
                ->all();
            array_splice($orderedSections, $position - 1, 0, [[
                'id' => $sectionId,
                'clave' => $this->stringValue($data, 'key'),
                'titulo' => $this->stringValue($data, 'title'),
                'descripcion' => null,
                'posicion' => $position,
                'bloques' => $blocks,
            ]]);
            $orderedSections = array_map(
                fn (array $section, int $index): array => [...$section, 'posicion' => $index + 1],
                $orderedSections,
                array_keys($orderedSections),
            );
            $template->update(['documento' => [
                'version_esquema' => 1,
                'secciones' => $orderedSections,
            ]]);

            foreach ($fieldDefinitions as $fieldDefinition) {
                FieldDefinition::query()->create($fieldDefinition);
            }

            $section = $template->fresh()->sectionOrFail($sectionId);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'plantilla.seccion_creada',
                resourceType: 'seccion_plantilla',
                resourceId: $section->id,
                result: 'exito',
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $section;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(TemplateSection $section, array $data, User $actor, Request $request): TemplateSection
    {
        $activeRole = $this->roles->resolve($request);
        // Con el proceso institucional abierto, el formato está en uso: se pausa antes.
        $this->locks->assertTemplateEditable();

        return $this->work->replace($request, function () use ($activeRole, $actor, $data, $request, $section): TemplateSection {
            $template = SyllabusTemplate::query()->whereKey($section->plantilla_id)->lockForUpdate()->firstOrFail();
            $section = $template->updateSectionDocument($section->id, [
                'titulo' => $this->stringValue($data, 'title'),
            ]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'plantilla.seccion_actualizada',
                resourceType: 'seccion_plantilla',
                resourceId: $section->id,
                result: 'exito',
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $section;
        });
    }

    /** @param array<string, mixed> $data */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw ValidationException::withMessages([$key => 'El valor no es válido.']);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function fields(array $data): array
    {
        if (is_array($data['fields'] ?? null) && $data['fields'] !== []) {
            return array_values(array_filter($data['fields'], is_array(...)));
        }

        return [[
            'key' => $this->stringValue($data, 'first_field_key'),
            'label' => $this->stringValue($data, 'first_field_label'),
            'content_type' => $this->stringValue($data, 'first_field_content_type'),
        ]];
    }
}
