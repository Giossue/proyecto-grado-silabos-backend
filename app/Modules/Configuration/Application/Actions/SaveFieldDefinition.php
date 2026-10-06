<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Application\SyllabusTemplateCatalog;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveFieldDefinition
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
        private readonly SyllabusTemplateCatalog $catalog,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(string $templateId, array $data, User $actor, Request $request): ?FieldDefinition
    {
        $this->locks->assertTemplateEditable();

        return $this->persist(null, $templateId, $data, $actor, $request);
    }

    /** @param array<string, mixed> $data */
    public function update(FieldDefinition $field, array $data, User $actor, Request $request): FieldDefinition
    {
        $this->locks->assertTemplateEditable();

        return $this->persist($field, $field->plantilla_id, $data, $actor, $request) ?? $field;
    }

    /** @param array<string, mixed> $data */
    private function persist(
        ?FieldDefinition $field,
        string $templateId,
        array $data,
        User $actor,
        Request $request,
    ): ?FieldDefinition {
        $activeRole = $this->roles->resolve($request);

        return $this->work->replaceTemplate(function () use ($actor, $activeRole, $data, $field, $request, $templateId): ?FieldDefinition {
            $template = SyllabusTemplate::query()->whereKey($templateId)->lockForUpdate()->firstOrFail();

            if ($field === null && ($data['preset'] ?? null) === 'identificacion') {
                throw ValidationException::withMessages(['preset' => 'La identificación institucional se agrega como sección nueva.']);
            }
            if ($field === null && isset($data['preset'])) {
                $data = $this->catalog->expand($data, $templateId);
            }
            $contentType = $this->stringValue($data, 'content_type');
            $data['label'] = trim((string) ($data['label'] ?? ''));
            $condition = array_key_exists('show_when', $data)
                ? ['mostrar_si' => FieldConditions::normalize($data['show_when'])]
                : [];

            $customBlock = $field?->block;
            if ($field !== null && $customBlock !== null && is_array($customBlock->configuracion['document'] ?? null)) {
                abort_unless($customBlock->id === $this->stringValue($data, 'block_id'), 404);
                if ($contentType !== $customBlock->configuredContentType()) {
                    throw ValidationException::withMessages(['content_type' => 'La forma de un campo no se cambia. Elimínelo y cree uno nuevo con la forma que necesita.']);
                }
                if ($data['key'] !== $field->clave) {
                    throw ValidationException::withMessages(['content_type' => 'Use Editar diseño para cambiar la estructura y los campos de esta sección.']);
                }
                if (isset($customBlock->configuracion['detached_fields'][$field->clave])) {
                    throw ValidationException::withMessages(['field' => 'Este campo se quitó del diseño; ya no está en el formulario.']);
                }
                $field->update([
                    'etiqueta' => $data['label'], 'ayuda' => $data['help'] ?? null,
                    'opciones' => $data['options'] ?? $field->opciones,
                    ...$this->profileAttributes($field, $data),
                    ...$condition,
                ]);
                if (array_key_exists('initial_content', $data)) {
                    $configuration = $customBlock->configuracion;
                    unset($configuration['initial_content']);
                    $customBlock->update(['configuracion' => [...$configuration, ...$this->initialContent($data)]]);
                }
                $this->applyOrientation($customBlock, $data);
                $this->auditField($field, 'plantilla.campo_actualizado', $actor, $activeRole?->id, $request);

                return $field;
            }

            if ($field !== null && $contentType === 'institutional') {
                $field->update([
                    'etiqueta' => $data['label'], 'ayuda' => $data['help'] ?? null,
                    ...$condition,
                ]);
                $block = $template->updateBlockDocument($field->campo_plantilla_id, ['titulo' => $data['label']]);
                $this->applyOrientation($block, $data);
                $this->auditField($field, 'plantilla.campo_actualizado', $actor, $activeRole?->id, $request);

                return $field;
            }

            if ($field === null && in_array($contentType, ['free_text', 'template'], true)) {
                $block = $this->createBlock($template, $data, 'text');
                $label = $data['label'] !== '' ? $data['label'] : $block->section->titulo;
                $block->update(['configuracion' => [...$block->configuracion, 'document' => $contentType === 'free_text'
                    ? TemplateDocumentDefaults::freeTextSheet($data['key'], $label)
                    : TemplateDocumentDefaults::blankSheet(),
                    ...$this->initialContent($data)]]);
                $this->applyOrientation($block, $data);
                $this->audit->execute(
                    actorId: $actor->id, roleAssignmentId: $activeRole?->id, action: 'plantilla.bloque_creado',
                    resourceType: 'bloque_plantilla', resourceId: $block->id, result: 'exito',
                );
                if ($contentType === 'template') {
                    return null;
                }
                $field = FieldDefinition::query()->create([
                    'plantilla_id' => $template->id, 'campo_plantilla_id' => $block->id, 'clave' => $data['key'],
                    'etiqueta' => $label, 'ayuda' => $data['help'] ?? null, 'tipo' => 'texto_libre',
                    'posicion' => 1, ...$this->profileAttributes(null, $data), ...$condition,
                ]);
                $this->auditField($field, 'plantilla.campo_creado', $actor, $activeRole?->id, $request);

                return $field;
            }
            if ($field === null) {
                throw ValidationException::withMessages(['content_type' => 'Elija Texto libre o Personalizado.']);
            }
            $block = $this->updateBlock($field, $template, $data, $contentType);
            $this->applyOrientation($block, $data);
            $attributes = [
                'plantilla_id' => $template->id,
                'campo_plantilla_id' => $block->id,
                'clave' => $data['key'],
                'etiqueta' => $data['label'],
                'ayuda' => $data['help'] ?? null,
                'tipo' => $this->fieldType($contentType),
                ...$this->profileAttributes($field, $data),
                'opciones' => $contentType === 'multiple_choice' ? ($data['options'] ?? $field->opciones) : null,
                ...$condition,
            ];

            $field->update($attributes);
            $this->auditField($field, 'plantilla.campo_actualizado', $actor, $activeRole?->id, $request);

            return $field;
        });
    }

    /**
     * Contenido que trae el campo en cada sílabo nuevo (por ejemplo, la tabla de planificación).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function initialContent(array $data): array
    {
        $content = $data['initial_content'] ?? null;
        if (! is_array($content) || ! FreeText::isDocument($content)) {
            return [];
        }
        $document = FreeText::normalize($content, 'initial_content');

        return FreeText::isEmpty($document) && ! $this->hasTable($document) ? [] : ['initial_content' => $document];
    }

    /** @param array<string, mixed> $document */
    private function hasTable(array $document): bool
    {
        foreach (FreeText::blocks($document) as $block) {
            if (($block['type'] ?? null) === 'table') {
                return true;
            }
        }

        return false;
    }

    /**
     * Herencia desde la ficha de la asignatura: solo los campos de texto libre pueden llenarse desde
     * ella. El resto de la herencia («asignaturas», identificación) no se cambia.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileAttributes(?FieldDefinition $field, array $data): array
    {
        if ($field?->origen_maestro === 'asignaturas') {
            return ['heredado' => true, 'origen_maestro' => 'asignaturas', 'editable_docente' => false];
        }
        $fromProfile = (bool) ($data['from_profile'] ?? $field?->fromProfile() ?? false);
        if (! $fromProfile || ($field !== null && $field->tipo !== 'texto_libre')) {
            return ['heredado' => false, 'origen_maestro' => null, 'editable_docente' => true];
        }

        return [
            'heredado' => true,
            'origen_maestro' => FieldDefinition::ORIGIN_PROFILE,
            'editable_docente' => (bool) ($data['teacher_editable'] ?? ($field === null ? false : $field->editable_docente)),
        ];
    }

    /** @param array<string, mixed> $data */
    private function applyOrientation(TemplateBlock $block, array $data): void
    {
        if (! array_key_exists('page_orientation', $data)) {
            return;
        }
        $configuration = $block->configuracion ?? [];
        if (is_string($data['page_orientation'])) {
            $configuration['page_orientation'] = $data['page_orientation'];
        } else {
            unset($configuration['page_orientation']);
        }
        $block->update(['configuracion' => $configuration]);
    }

    private function auditField(FieldDefinition $field, string $action, User $actor, ?string $roleAssignmentId, Request $request): void
    {
        $this->audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $roleAssignmentId,
            action: $action,
            resourceType: 'definicion_campo',
            resourceId: $field->id,
            result: 'exito',
        );
    }

    /** @param array<string, mixed> $data */
    private function createBlock(SyllabusTemplate $template, array $data, string $contentType): TemplateBlock
    {
        $sectionId = $this->stringValue($data, 'section_id');
        $section = $template->sectionOrFail($sectionId);
        $blocks = $section->blocks()->sortBy('posicion')->values();
        $position = min(
            max(1, ((int) ($data['position'] ?? $blocks->count() + 1))),
            $blocks->count() + 1,
        );
        $configuration = ['content_type' => $contentType];

        $blockId = (string) Str::uuid();
        $orderedBlocks = $blocks
            ->map(fn (TemplateBlock $block): array => $block->toDocument())
            ->all();
        array_splice($orderedBlocks, $position - 1, 0, [[
            'id' => $blockId,
            'clave' => $data['key'],
            'titulo' => $data['label'],
            'configuracion' => $configuration,
            'posicion' => $position,
        ]]);
        $orderedBlocks = array_map(
            fn (array $block, int $index): array => [...$block, 'posicion' => $index + 1],
            $orderedBlocks,
            array_keys($orderedBlocks),
        );
        $template->updateSectionDocument($section->id, ['bloques' => $orderedBlocks]);

        return $template->blockOrFail($blockId);
    }

    /** @param array<string, mixed> $data */
    private function updateBlock(
        FieldDefinition $field,
        SyllabusTemplate $template,
        array $data,
        string $contentType,
    ): TemplateBlock {
        $blockId = $this->stringValue($data, 'block_id');
        $block = $template->blockOrFail($blockId);
        abort_unless($block->id === $field->campo_plantilla_id, 404);

        $configuration = $block->getAttribute('configuracion');

        if ($block->contentType() !== $contentType) {
            throw ValidationException::withMessages(['content_type' => 'La forma de un campo no se cambia. Elimínelo y cree uno nuevo con la forma que necesita.']);
        }

        $block->update([
            'titulo' => $data['label'],
            'configuracion' => [
                ...(is_array($configuration) ? $configuration : []),
                'content_type' => $contentType,
            ],
        ]);

        return $block;
    }

    private function fieldType(string $contentType): string
    {
        return match ($contentType) {
            'text' => 'texto_largo',
            'institutional' => 'referencia_maestra',
            'boolean' => 'booleano',
            'multiple_choice' => 'seleccion_multiple',
            default => 'texto_largo',
        };
    }

    /** @param array<string, mixed> $data */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw ValidationException::withMessages([$key => 'El identificador no es válido.']);
        }

        return $value;
    }
}
