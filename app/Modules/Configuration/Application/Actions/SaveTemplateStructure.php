<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\SectionDesigns;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SaveTemplateStructure
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
        private readonly SectionDesigns $designs,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSection(SyllabusTemplate $template, array $data, User $actor, Request $request): TemplateSection
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        return $this->work->replaceTemplate(function () use ($activeRole, $actor, $data, $template): TemplateSection {
            $locked = SyllabusTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $count = $locked->sections()->count();
            $design = is_string($data['design'] ?? null) ? $data['design'] : null;
            $definitions = [];
            if ($design !== null) {
                $this->designs->assertAvailable($locked, $design);
                $built = $this->designs->instantiate($design, $locked->id, $count + 1, is_string($data['title'] ?? null) ? $data['title'] : null);
                $section = $built['section'];
                $definitions = $built['definitions'];
            } else {
                $section = [
                    'id' => (string) Str::uuid(),
                    'clave' => (string) $data['key'],
                    'titulo' => (string) $data['title'],
                    'descripcion' => null,
                    'posicion' => $count + 1,
                    'bloques' => [],
                    'orientacion' => $data['orientation'] ?? 'portrait',
                ];
            }
            $sectionId = (string) $section['id'];
            $locked->appendSection($section);
            foreach ($definitions as $definition) {
                FieldDefinition::query()->create($definition);
            }
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function createField(SyllabusTemplate $template, string $sectionId, array $data, User $actor, Request $request): FieldDefinition
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        return $this->work->replaceTemplate(function () use ($activeRole, $actor, $data, $sectionId, $template): FieldDefinition {
            $locked = SyllabusTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $section = $locked->sectionOrFail($sectionId);
            $content = FieldDocument::normalize($data['content']);
            $label = trim((string) ($data['label'] ?? ''));
            $count = $section->blocks()->count();

            $blockId = (string) Str::uuid();
            $locked->appendBlock($section->id, [
                'id' => $blockId,
                'clave' => (string) $data['key'],
                'titulo' => $label,
                'configuracion' => [],
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
                'quien_llena' => $data['quien_llena'],
                'contenido' => $content,
                'posicion' => 1,
            ]);
            $this->auditField($field, 'plantilla.campo_creado', $actor, $activeRole?->id);

            return $field;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateField(SyllabusTemplate $template, FieldDefinition $field, array $data, User $actor, Request $request): FieldDefinition
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        return $this->work->replaceTemplate(function () use ($activeRole, $actor, $data, $field, $template): FieldDefinition {
            $locked = SyllabusTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $label = array_key_exists('label', $data) ? trim((string) $data['label']) : $field->etiqueta;

            $field->update([
                'etiqueta' => $label,
                'ayuda' => array_key_exists('help', $data) ? $data['help'] : $field->ayuda,
                'quien_llena' => $data['quien_llena'] ?? $field->quien_llena,
                'contenido' => array_key_exists('content', $data) ? FieldDocument::normalize($data['content']) : $field->contenido,
            ]);
            $locked->updateBlockDocument($field->campo_plantilla_id, ['titulo' => $label]);
            $this->auditField($field, 'plantilla.campo_actualizado', $actor, $activeRole?->id);

            return $field;
        });
    }

    private function auditField(FieldDefinition $field, string $action, User $actor, ?string $roleAssignmentId): void
    {
        $this->audit->execute(
            actorId: $actor->id, roleAssignmentId: $roleAssignmentId, action: $action,
            resourceType: 'definicion_campo', resourceId: $field->id, result: 'exito',
        );
    }
}
