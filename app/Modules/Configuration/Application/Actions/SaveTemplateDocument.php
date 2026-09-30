<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\CurricularFieldOptions;
use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\TemplateDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SaveTemplateDocument
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
    ) {}

    public static function fingerprint(TemplateBlock $block): string
    {
        return hash('sha256', json_encode([
            $block->titulo,
            $block->configuracion,
            $block->fields()->get()->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $document */
    public function execute(TemplateBlock $block, array $document, string $fingerprint, User $actor, Request $request): void
    {
        abort_unless($actor->can('manage-templates'), 403);
        $document = $this->applyPropertyLabels($document, $request->input('properties', []), false);
        $normalized = TemplateDocument::normalize($document, array_keys(TemplateVariables::definitions()));
        $this->locks->assertTemplateEditable();
        $this->work->replace($request, function () use ($block, $normalized, $fingerprint, $actor, $request): void {
            $template = SyllabusTemplate::query()->whereKey($block->plantilla_id)->lockForUpdate()->firstOrFail();
            $this->locks->assertTemplateEditable();
            $block = $template->blockOrFail($block->id);
            if ($block->contentType() === 'fixed' && ! self::hasContent($normalized)) {
                throw ValidationException::withMessages(['document' => 'Escriba el texto fijo antes de guardar.']);
            }
            if (! hash_equals(self::fingerprint($block), $fingerprint)) {
                throw ValidationException::withMessages(['fingerprint' => 'La plantilla cambió en otra sesión. Recargue antes de guardar; su diseño no fue sobrescrito.']);
            }
            $fields = $block->fields->keyBy('clave');
            $documentFieldKeys = array_map(
                static fn (array $node): string => $node['attrs']['key'],
                TemplateDocument::nodes($normalized, 'field'),
            );
            foreach ($request->input('properties', []) as $index => $property) {
                $field = $fields->get($property['key']);
                if (($field === null && ! in_array($property['key'], $documentFieldKeys, true))
                    || ($field !== null && isset($block->configuracion['detached_fields'][$field->clave]))) {
                    throw ValidationException::withMessages(["properties.$index.key" => 'El campo no pertenece al diseño actual.']);
                }
            }
            $normalized = $this->applyPropertyLabels($normalized, $request->input('properties', []));
            $used = [];
            $toCreate = [];
            $labels = [];
            $optionSources = [];
            foreach (TemplateDocument::nodes($normalized, 'field') as $node) {
                $attrs = $node['attrs'];
                $key = $attrs['key'];
                $source = CurricularFieldOptions::fromAttributes($attrs);
                foreach ($source as $sourceKey) {
                    if (! CurricularFieldOptions::sourceIsAllowed($sourceKey)) {
                        TemplateDocument::fail('El origen de opciones debe ser un concepto curricular de tipo lista.');
                    }
                }
                if (array_key_exists($key, $optionSources) && $optionSources[$key] !== $source) {
                    TemplateDocument::fail('Un campo debe conservar el mismo origen de opciones en todas sus apariciones.');
                }
                $optionSources[$key] = $source;
                if (isset($labels[$key]) && $labels[$key] !== $attrs['label']) {
                    TemplateDocument::fail('Un campo debe conservar el mismo nombre en todas sus apariciones.');
                }
                $labels[$key] = $attrs['label'];
                $used[] = $key;
                if (isset($fields[$key])) {
                    if ($source !== [] && ! in_array($fields[$key]->tipo, CurricularFieldOptions::CHOICE_TYPES, true)) {
                        TemplateDocument::fail('Agregue una pregunta de selección para usar opciones curriculares.');
                    }
                    if (in_array($fields[$key]->tipo, CurricularFieldOptions::CHOICE_TYPES, true)) {
                        $staticOptions = $attrs['options'] ?? $fields[$key]->opciones;
                        if ($source === [] && ($staticOptions === null || $staticOptions === [])) {
                            TemplateDocument::fail('Un campo de selección necesita opciones fijas o una lista curricular.');
                        }
                        $fields[$key]->update(['opciones' => $source === [] ? $staticOptions : null]);
                    }
                    $choice = $attrs['choice'] ?? null;
                    if ($choice !== null && ! in_array($choice, $this->optionValues($fields[$key]), true)) {
                        TemplateDocument::fail('La condición usa una opción que no pertenece al campo.');
                    }

                    continue;
                }
                if (! in_array($attrs['kind'], TemplateDocument::FIELD_TYPES, true)
                    || FieldDefinition::query()->where('plantilla_id', $block->plantilla_id)->where('clave', $key)->exists()) {
                    TemplateDocument::fail('El campo pertenece a otra sección o su tipo no está permitido.');
                }
                if ($attrs['kind'] === 'seleccion_multiple' && $source === []) {
                    TemplateDocument::fail('Seleccione un catálogo curricular para la nueva selección múltiple.');
                }
                $definition = [
                    'key' => $attrs['key'],
                    'label' => $attrs['label'],
                    'kind' => $attrs['kind'],
                    'options' => $attrs['options'] ?? null,
                ];
                if (in_array($attrs['kind'], CurricularFieldOptions::CHOICE_TYPES, true)
                    && $definition['options'] === null && $source === []) {
                    TemplateDocument::fail('Un campo de selección necesita sus opciones.');
                }
                if (isset($toCreate[$key]) && $toCreate[$key] !== $definition) {
                    TemplateDocument::fail('Dos campos distintos tienen el mismo nombre. Cambie el nombre de uno.');
                }
                $toCreate[$key] = $definition;
            }
            $configuration = $block->configuracion ?? [];
            $configuration['options_sources'] = array_filter($optionSources, static fn (array $sources): bool => $sources !== []);
            foreach ($labels as $key => $label) {
                $fields->get($key)?->update(['etiqueta' => $label]);
            }
            $position = $block->fields->count();
            foreach ($toCreate as $key => $attrs) {
                $block->fields()->create([
                    'plantilla_id' => $block->plantilla_id,
                    'campo_plantilla_id' => $block->id,
                    'clave' => $key,
                    'etiqueta' => $attrs['label'], 'tipo' => $attrs['kind'],
                    'opciones' => $attrs['options'],
                    'heredado' => false, 'editable_docente' => true,
                    'posicion' => ++$position,
                ]);
            }
            $detached = $configuration['detached_fields'] ?? [];
            foreach ($block->fields->where('heredado', false) as $field) {
                if (in_array($field->clave, $used, true)) {
                    if (isset($detached[$field->clave])) {
                        $field->update($detached[$field->clave]);
                        unset($detached[$field->clave]);
                    }
                } else {
                    $detached[$field->clave] ??= $field->only(['editable_docente']);
                    $field->update(['editable_docente' => false]);
                }
            }
            $configuration['detached_fields'] = $detached;
            $block->update([
                'titulo' => $request->has('title') ? $request->string('title')->trim()->value() : $block->titulo,
                'configuracion' => [...$configuration, 'document' => $normalized],
            ]);
            $this->saveConditions($block, $normalized, $detached);
            $this->saveProperties($block, $request, $detached);
            $this->auditDesign($block, $actor, $request, count(array_unique($used)));
        });
    }

    /** @param array<string, mixed> $node */
    private static function hasContent(array $node): bool
    {
        if (in_array($node['type'] ?? null, ['text', 'variable', 'field', 'table'], true)) {
            return true;
        }
        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child) && self::hasContent($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $detached
     */
    private function saveConditions(TemplateBlock $block, array $document, array $detached): void
    {
        foreach (TemplateDocument::nodes($document, 'field') as $node) {
            $key = $node['attrs']['key'];
            if (($node['attrs']['choice'] ?? null) !== null || isset($detached[$key])) {
                continue;
            }
            $condition = $node['attrs']['showWhen'] ?? null;
            $error = FieldConditions::error($block->plantilla_id, $key, $condition);
            if ($error !== null) {
                TemplateDocument::fail($error);
            }
            $block->fields()->where('clave', $key)->where('heredado', false)->update([
                'mostrar_si' => $condition === null ? null : json_encode(FieldConditions::normalize($condition), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /** @param array<string, mixed> $detached */
    private function saveProperties(TemplateBlock $block, Request $request, array $detached): void
    {
        foreach ($request->input('properties', []) as $property) {
            $attributes = ['etiqueta' => $property['label'] ?? '', 'ayuda' => $property['help']];
            if (array_key_exists('show_when', $property) && ! isset($detached[$property['key']])) {
                $condition = FieldConditions::normalize($property['show_when']);
                $attributes['mostrar_si'] = $condition === null ? null : json_encode($condition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            $block->fields()->where('clave', $property['key'])->update($attributes);
        }
        $configuration = [...($block->configuracion ?? []), 'detached_fields' => $detached];
        if ($request->has('page_orientation')) {
            $orientation = $request->input('page_orientation');
            if (is_string($orientation)) {
                $configuration['page_orientation'] = $orientation;
            } else {
                unset($configuration['page_orientation']);
            }
        }
        $block->update([
            'titulo' => $request->has('title') ? $request->string('title')->trim()->value() : $block->titulo,
            'configuracion' => $configuration,
        ]);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private function applyPropertyLabels(array $document, array $properties, bool $applyLabels = true): array
    {
        $labels = [];
        $sources = [];
        foreach ($properties as $property) {
            if ($applyLabels) {
                $labels[$property['key']] = trim($property['label']);
            }
            if (array_key_exists('options_source', $property) || array_key_exists('options_sources', $property)) {
                $sources[$property['key']] = CurricularFieldOptions::fromAttributes($property);
            }
        }
        $visit = function (array $node) use (&$visit, $labels, $sources): array {
            if (($node['type'] ?? null) === 'field'
                && isset($labels[$node['attrs']['key'] ?? null])) {
                $node['attrs']['label'] = $labels[$node['attrs']['key']];
            }
            if (($node['type'] ?? null) === 'field' && array_key_exists($node['attrs']['key'], $sources)) {
                $fieldSources = $sources[$node['attrs']['key']];
                $node['attrs']['options_sources'] = $fieldSources;
                $node['attrs']['options_source'] = count($fieldSources) === 1 ? $fieldSources[0] : null;
                if ($fieldSources !== []) {
                    $node['attrs']['options'] = null;
                }
            }
            if (isset($node['content'])) {
                $node['content'] = array_map($visit, $node['content']);
            }

            return $node;
        };

        return $visit($document);
    }

    private function auditDesign(TemplateBlock $block, User $actor, Request $request, int $fields): void
    {
        $this->audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $this->roles->resolve($request)?->id,
            action: 'plantilla.diseno_actualizado', resourceType: 'bloque_plantilla',
            resourceId: $block->id, result: 'exito',
            metadata: ['fields' => $fields],
        );
    }

    /** @return list<string> */
    private function optionValues(FieldDefinition $field): array
    {
        return array_values(collect($field->opciones ?? [])
            ->map(static function (mixed $option): ?string {
                if (is_string($option) || is_int($option)) {
                    return (string) $option;
                }

                return is_array($option) && (is_string($option['value'] ?? null) || is_int($option['value'] ?? null))
                    ? (string) $option['value']
                    : null;
            })
            ->filter(static fn (?string $value): bool => $value !== null)
            ->all());
    }
}
