<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Domain\FieldTypes\FieldTypeRegistry;
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

/**
 * Plantilla simplificada de I-195: secciones con campos de los cuatro tipos del registro.
 * Cada campo es un bloque de la sección más su definición; el tipo y su configuración
 * los valida el registro, nunca esta clase.
 */
class SaveTemplateStructure
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
        private readonly FieldTypeRegistry $types,
    ) {}

    /** @param array<string, mixed> $data */
    public function createSection(SyllabusTemplate $template, array $data, User $actor, Request $request): TemplateSection
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        return $this->work->replaceTemplate(function () use ($activeRole, $actor, $data, $template): TemplateSection {
            $locked = SyllabusTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $count = $locked->sections()->count();
            $sectionId = (string) Str::uuid();
            $locked->appendSection([
                'id' => $sectionId,
                'clave' => (string) $data['key'],
                'titulo' => (string) $data['title'],
                'descripcion' => null,
                'posicion' => $count + 1,
                'bloques' => [],
            ]);
            $position = min(max(1, (int) ($data['position'] ?? $count + 1)), $count + 1);
            if ($position <= $count) {
                $ids = $locked->sections()->sortBy('posicion')->pluck('id')->reject(fn ($id) => $id === $sectionId)->values()->all();
                array_splice($ids, $position - 1, 0, [$sectionId]);
                $locked->reorderSections($ids);
            }

            $this->audit->execute(
                actorId: $actor->id, roleAssignmentId: $activeRole?->id, action: 'plantilla.seccion_creada',
                resourceType: 'seccion_plantilla', resourceId: $sectionId, result: 'exito',
            );

            return $locked->fresh()->sectionOrFail($sectionId);
        });
    }

    /** @param array<string, mixed> $data */
    public function createField(SyllabusTemplate $template, string $sectionId, array $data, User $actor, Request $request): FieldDefinition
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        return $this->work->replaceTemplate(function () use ($activeRole, $actor, $data, $sectionId, $template): FieldDefinition {
            $locked = SyllabusTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $section = $locked->sectionOrFail($sectionId);
            $type = $this->types->get((string) $data['type']);
            $config = $this->config($data['type'], $data['config'] ?? []);
            $label = trim((string) ($data['label'] ?? ''));
            $count = $section->blocks()->count();

            $blockId = (string) Str::uuid();
            $locked->appendBlock($section->id, [
                'id' => $blockId,
                'clave' => (string) $data['key'],
                'titulo' => $label,
                'configuracion' => ['content_type' => 'text', 'document' => $type->teacherEditable() || $type->key() === 'ficha'
                    ? TemplateDocumentDefaults::freeTextSheet((string) $data['key'], $label)
                    : TemplateDocumentDefaults::blankSheet()],
                'posicion' => $count + 1,
            ]);
            $position = min(max(1, (int) ($data['position'] ?? $count + 1)), $count + 1);
            if ($position <= $count) {
                $ids = $locked->sectionOrFail($section->id)->blocks()->sortBy('posicion')->pluck('id')->reject(fn ($id) => $id === $blockId)->values()->all();
                array_splice($ids, $position - 1, 0, [$blockId]);
                $locked->reorderBlocks($section->id, $ids);
            }

            $field = FieldDefinition::query()->create([
                'plantilla_id' => $locked->id,
                'campo_plantilla_id' => $blockId,
                'clave' => (string) $data['key'],
                'etiqueta' => $label,
                'ayuda' => $data['help'] ?? null,
                'tipo' => $type->key(),
                'posicion' => 1,
                ...$this->attributesFor($type->key(), $config),
            ]);
            $this->auditField($field, 'plantilla.campo_creado', $actor, $activeRole?->id);

            return $field;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateField(SyllabusTemplate $template, FieldDefinition $field, array $data, User $actor, Request $request): FieldDefinition
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        return $this->work->replaceTemplate(function () use ($activeRole, $actor, $data, $field, $template): FieldDefinition {
            $locked = SyllabusTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $typeKey = $field->fieldType()->key();
            if (isset($data['type']) && $data['type'] !== $typeKey) {
                throw ValidationException::withMessages(['type' => 'El tipo de un campo no se cambia. Elimínelo y cree uno nuevo con el tipo que necesita.']);
            }
            $config = array_key_exists('config', $data) ? $this->config($typeKey, $data['config'] ?? []) : $field->typeConfig();
            $label = array_key_exists('label', $data) ? trim((string) $data['label']) : $field->etiqueta;

            $field->update([
                'etiqueta' => $label,
                'ayuda' => array_key_exists('help', $data) ? $data['help'] : $field->ayuda,
                ...$this->attributesFor($typeKey, $config),
            ]);
            $locked->updateBlockDocument($field->campo_plantilla_id, ['titulo' => $label]);
            $this->auditField($field, 'plantilla.campo_actualizado', $actor, $activeRole?->id);

            return $field;
        });
    }

    /** @return array<string, mixed> */
    private function config(mixed $typeKey, mixed $config): array
    {
        try {
            return $this->types->get((string) $typeKey)->normalizeConfig(is_array($config) ? $config : []);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors['config.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Columnas de la definición que dependen del tipo (herencia y quién lo edita).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function attributesFor(string $typeKey, array $config): array
    {
        $base = ['opciones' => $config === [] ? null : $config, 'mostrar_si' => null];

        return $base + match ($typeKey) {
            'ficha' => [
                'heredado' => true,
                'origen_maestro' => FieldDefinition::ORIGIN_PROFILE,
                'editable_docente' => (bool) ($config['adjustable'] ?? false),
            ],
            'automatico' => ['heredado' => true, 'origen_maestro' => (string) $config['source'], 'editable_docente' => false],
            'fijo' => ['heredado' => false, 'origen_maestro' => null, 'editable_docente' => false],
            default => ['heredado' => false, 'origen_maestro' => null, 'editable_docente' => true],
        };
    }

    private function auditField(FieldDefinition $field, string $action, User $actor, ?string $roleAssignmentId): void
    {
        $this->audit->execute(
            actorId: $actor->id, roleAssignmentId: $roleAssignmentId, action: $action,
            resourceType: 'definicion_campo', resourceId: $field->id, result: 'exito',
        );
    }
}
