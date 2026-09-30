<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\CurricularFieldOptions;
use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
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

        return $this->work->replace($request, function () use ($actor, $activeRole, $data, $field, $request, $templateId): ?FieldDefinition {
            $template = SyllabusTemplate::query()->whereKey($templateId)->lockForUpdate()->firstOrFail();

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
                    'opciones' => CurricularFieldOptions::fromAttributes($data) !== [] ? null : ($data['options'] ?? $field->opciones),
                    ...$condition,
                ]);
                $this->applyOrientation($customBlock, $data);
                $this->applyOptionsSource($customBlock, $field, $data);
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
                    : TemplateDocumentDefaults::blankSheet()]]);
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
                    'heredado' => false, 'editable_docente' => true,
                    'posicion' => 1, ...$condition,
                ]);
                $this->auditField($field, 'plantilla.campo_creado', $actor, $activeRole?->id, $request);

                return $field;
            }
            if ($field === null) {
                throw ValidationException::withMessages(['content_type' => 'Elija Texto libre o Personalizado.']);
            }
            $block = $this->updateBlock($field, $template, $data, $contentType);
            $this->applyOrientation($block, $data);
            $inherited = $field->heredado;
            $attributes = [
                'plantilla_id' => $template->id,
                'campo_plantilla_id' => $block->id,
                'clave' => $data['key'],
                'etiqueta' => $data['label'],
                'ayuda' => $data['help'] ?? null,
                'tipo' => $this->fieldType($contentType),
                'heredado' => $inherited,
                'origen_maestro' => $field->heredado ? $field->origen_maestro : null,
                'editable_docente' => ! $inherited,
                'opciones' => $contentType === 'multiple_choice' ? ($data['options'] ?? $field->opciones) : null,
                ...$condition,
            ];

            $field->update($attributes);
            $this->applyOptionsSource($block, $field, $data);
            $this->auditField($field, 'plantilla.campo_actualizado', $actor, $activeRole?->id, $request);

            return $field;
        });
    }

    /** @param array<string, mixed> $data */
    private function applyOptionsSource(TemplateBlock $block, FieldDefinition $field, array $data): void
    {
        if (! array_key_exists('options_source', $data) && ! array_key_exists('options_sources', $data)) {
            return;
        }
        $sources = CurricularFieldOptions::fromAttributes($data);
        foreach ($sources as $source) {
            if (! in_array($field->tipo, CurricularFieldOptions::CHOICE_TYPES, true) || ! CurricularFieldOptions::sourceIsAllowed($source)) {
                throw ValidationException::withMessages(['options_sources' => 'Seleccione listas curriculares para una pregunta de selección.']);
            }
        }
        $configuration = $block->configuracion;
        $configuration['options_sources'][$field->clave] = $sources;
        if (is_array($configuration['document'] ?? null)) {
            $visit = function (array $node) use (&$visit, $field, $sources): array {
                if (($node['type'] ?? null) === 'field' && ($node['attrs']['key'] ?? null) === $field->clave) {
                    if ($sources !== [] && ($node['attrs']['choice'] ?? null) !== null) {
                        throw ValidationException::withMessages(['options_source' => 'Una marca de opción fija no admite opciones curriculares.']);
                    }
                    $node['attrs']['options_sources'] = $sources;
                    $node['attrs']['options_source'] = count($sources) === 1 ? $sources[0] : null;
                    $node['attrs']['options'] = $sources !== [] ? null : array_map(
                        static fn (mixed $option): mixed => is_array($option) ? ($option['value'] ?? '') : $option,
                        $field->opciones ?? [],
                    );
                }
                if (is_array($node['content'] ?? null)) {
                    $node['content'] = array_map($visit, $node['content']);
                }

                return $node;
            };
            $configuration['document'] = $visit($configuration['document']);
        }
        $block->update(['configuracion' => $configuration]);
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
