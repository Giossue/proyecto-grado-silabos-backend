<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\TableLayout;
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
                    throw ValidationException::withMessages(["properties.$index.key" => 'La parte no pertenece al diseño actual.']);
                }
            }
            $normalized = $this->applyPropertyLabels($normalized, $request->input('properties', []));
            $used = [];
            $toCreate = [];
            $labels = [];
            foreach (TemplateDocument::nodes($normalized, 'field') as $node) {
                $attrs = $node['attrs'];
                $key = $attrs['key'];
                if (isset($labels[$key]) && $labels[$key] !== $attrs['label']) {
                    TemplateDocument::fail('Una parte debe conservar el mismo nombre en todas sus apariciones.');
                }
                $labels[$key] = $attrs['label'];
                $used[] = $key;
                if (isset($fields[$key])) {
                    $choice = $attrs['choice'] ?? null;
                    if ($choice !== null && ! in_array($choice, $this->optionValues($fields[$key]), true)) {
                        TemplateDocument::fail('La condición usa una opción que no pertenece a la parte.');
                    }

                    continue;
                }
                if (! in_array($attrs['kind'], TemplateDocument::FIELD_TYPES, true)
                    || FieldDefinition::query()->where('plantilla_id', $block->plantilla_id)->where('clave', $key)->exists()) {
                    TemplateDocument::fail('La parte pertenece a otra sección o su tipo no está permitido.');
                }
                $definition = [
                    'key' => $attrs['key'],
                    'label' => $attrs['label'],
                    'kind' => $attrs['kind'],
                    'options' => $attrs['options'] ?? null,
                ];
                if ($attrs['kind'] === 'seleccion_unica' && $definition['options'] === null) {
                    TemplateDocument::fail('Una parte de selección necesita sus opciones.');
                }
                if (isset($toCreate[$key]) && $toCreate[$key] !== $definition) {
                    TemplateDocument::fail('Dos partes distintas tienen el mismo nombre. Cambie el nombre de una.');
                }
                $toCreate[$key] = $definition;
            }
            foreach (TemplateDocument::nodes($normalized, 'table') as $table) {
                $key = $table['attrs']['repeatKey'] ?? null;
                if ($key === null || isset($fields[$key])) {
                    continue;
                }
                $label = $table['attrs']['repeatLabel'] ?? null;
                if (! is_string($label) || $label === ''
                    || FieldDefinition::query()->where('plantilla_id', $block->plantilla_id)->where('clave', $key)->exists()) {
                    TemplateDocument::fail('No se reconoce el origen de las filas de la tabla.');
                }
                $definition = [
                    'key' => $key,
                    'label' => $label,
                    'kind' => 'repetible',
                    'options' => null,
                ];
                if (isset($toCreate[$key]) && $toCreate[$key] !== $definition) {
                    TemplateDocument::fail('Dos partes distintas tienen el mismo nombre. Cambie el nombre de una.');
                }
                $toCreate[$key] = $definition;
                $labels[$key] = $label;
            }
            $configuration = $block->configuracion ?? [];
            $layout = TableLayout::fromBlock($block);
            if ($layout === null && collect(TemplateDocument::nodes($normalized, 'table'))->contains(
                fn (array $table): bool => ($table['attrs']['repeatKey'] ?? null) !== null,
            )) {
                $layout = TableLayout::default();
            }
            $columnKeys = [];
            $headerKeys = [];
            $summedKeys = [];
            $hasTotalRow = false;
            $groupByUnit = (bool) ($layout['repeat']['enabled'] ?? false);
            $visualStructure = false;
            foreach (TemplateDocument::nodes($normalized, 'table') as $table) {
                $key = $table['attrs']['repeatKey'];
                if ($key === null) {
                    if (TemplateDocument::nodes($table, 'column') !== []) {
                        TemplateDocument::fail('Las partes de fila deben estar dentro de su tabla.');
                    }

                    continue;
                }
                $existingRepeatField = isset($fields[$key]) && $fields[$key]->tipo === 'repetible';
                $newRepeatField = ($toCreate[$key]['kind'] ?? null) === 'repetible';
                if ((! $existingRepeatField && ! $newRepeatField) || $layout === null) {
                    TemplateDocument::fail('No se reconoce el origen de las filas de la tabla.');
                }
                if (is_bool($table['attrs']['groupByUnit'] ?? null)) {
                    $groupByUnit = $table['attrs']['groupByUnit'];
                }
                $visualStructure = $visualStructure || ($table['attrs']['visualStructure'] ?? false) === true;
                $used[] = $key;
                $hasRecords = false;
                $recordKeys = [];
                $totalKeys = [];
                foreach ($table['content'] as $row) {
                    $role = $row['attrs']['rowRole'];
                    $hasRecords = $hasRecords || $role === 'record';
                    $hasTotalRow = $hasTotalRow || $role === 'total';
                    foreach (TemplateDocument::nodes($row, 'column') as $node) {
                        $attrs = $node['attrs'];
                        if ($role === 'unit') {
                            $headerKeys[$attrs['key']] = ['key' => $attrs['key'], 'label' => $attrs['label']];
                        } elseif (in_array($role, ['record', 'total'], true)) {
                            if (! in_array($attrs['kind'], ['texto_largo', 'numero'], true)) {
                                TemplateDocument::fail('Las columnas admiten texto o número.');
                            }
                            // Un total solo suma números; un texto ahí quedaría siempre vacío.
                            if ($role === 'total' && $attrs['kind'] !== 'numero') {
                                TemplateDocument::fail('La fila de totales solo admite datos numéricos.');
                            }
                            $knownColumn = $columnKeys[$attrs['key']] ?? null;
                            if ($knownColumn !== null
                                && ($knownColumn['label'] !== $attrs['label'] || $knownColumn['kind'] !== $attrs['kind'])) {
                                TemplateDocument::fail('Una columna debe mantener el mismo nombre y tipo en toda la tabla.');
                            }
                            if ($knownColumn !== null
                                && ($knownColumn['role'] ?? null) !== null
                                && ($attrs['role'] ?? null) !== null
                                && $knownColumn['role'] !== $attrs['role']) {
                                TemplateDocument::fail('Una columna debe conservar la misma función en toda la tabla.');
                            }
                            if ($role === 'total' && ! TableLayout::summable('number', $attrs['role'] ?? null, $attrs['key'], $attrs['label'])) {
                                TemplateDocument::fail("La columna «{$attrs['label']}» no se suma; quítela de la fila de totales.");
                            }
                            $columnKeys[$attrs['key']] = [
                                ...($knownColumn ?? $attrs),
                                'role' => $attrs['role'] ?? $knownColumn['role'] ?? null,
                                'sum' => $role === 'total'
                                    ? true
                                    : ($attrs['sum'] ?? $knownColumn['sum'] ?? null),
                            ];
                            if ($role === 'record') {
                                $recordKeys[] = $attrs['key'];
                            } else {
                                $totalKeys[] = $attrs['key'];
                                $summedKeys[] = $attrs['key'];
                            }
                        } else {
                            TemplateDocument::fail('Una columna debe estar en una fila de datos, de unidad o de total.');
                        }
                    }
                }
                if (! $hasRecords || $recordKeys === []) {
                    TemplateDocument::fail('La tabla necesita al menos una fila que llene el docente.');
                }
                if (array_diff($totalKeys, $recordKeys) !== [] || array_intersect(array_keys($headerKeys), $recordKeys) !== []) {
                    TemplateDocument::fail('Los totales deben sumar columnas que llena el docente, y las columnas de unidad necesitan su propio nombre.');
                }
            }
            if ($layout !== null && $columnKeys !== []) {
                $known = array_column($layout['columns'], null, 'key');
                $columns = [];
                foreach ($columnKeys as $key => $attrs) {
                    $type = $attrs['kind'] === 'numero' ? 'number' : 'text';
                    if (isset($known[$key]) && $known[$key]['type'] !== $type) {
                        TemplateDocument::fail('No cambie el tipo de una columna que ya existe; agregue una columna nueva.');
                    }
                    $columns[] = [
                        'key' => $key, 'label' => $attrs['label'], 'type' => $type,
                        'group' => $known[$key]['group'] ?? null,
                        'band' => $known[$key]['band'] ?? null,
                        'sum' => $visualStructure
                            ? in_array($key, $summedKeys, true)
                            : ($attrs['sum'] ?? $known[$key]['sum'] ?? false),
                        'width' => $known[$key]['width'] ?? null,
                        'role' => $attrs['role'] ?? $known[$key]['role'] ?? null,
                    ];
                }
                $configuration['table'] = TableLayout::normalize([
                    ...$layout, 'columns' => $columns,
                    'header_fields' => array_values($headerKeys),
                    'totals' => [
                        ...$layout['totals'],
                        'enabled' => $visualStructure ? $hasTotalRow : $layout['totals']['enabled'],
                    ],
                    'repeat' => [
                        ...$layout['repeat'],
                        'enabled' => $visualStructure ? $groupByUnit : $layout['repeat']['enabled'],
                    ],
                ]);
            }
            foreach ($labels as $key => $label) {
                $fields->get($key)?->update(['etiqueta' => $label]);
            }
            $position = $block->fields->count();
            foreach ($toCreate as $key => $attrs) {
                $block->fields()->create([
                    'plantilla_id' => $block->plantilla_id,
                    'bloque_plantilla_id' => $block->id,
                    'clave' => $key,
                    'etiqueta' => $attrs['label'], 'tipo' => $attrs['kind'],
                    'opciones' => $attrs['options'],
                    'heredado' => false, 'editable_docente' => true,
                    'ia_habilitada' => false,
                    'posicion' => ++$position,
                ]);
            }
            // Las definiciones pueden estar referenciadas por datos y evidencia históricos.
            // Retirarlas del formulario no debe borrar esos datos ni dejar campos ocultos obligatorios.
            $detached = $configuration['detached_fields'] ?? [];
            foreach ($block->fields->where('heredado', false) as $field) {
                if (in_array($field->clave, $used, true)) {
                    if (isset($detached[$field->clave])) {
                        $field->update($detached[$field->clave]);
                        unset($detached[$field->clave]);
                    }
                } else {
                    $detached[$field->clave] ??= $field->only([
                        'editable_docente',
                        'ia_habilitada',
                    ]);
                    $field->update(['editable_docente' => false, 'ia_habilitada' => false]);
                }
            }
            $configuration['detached_fields'] = $detached;
            $block->update([
                'titulo' => $request->has('title') ? $request->string('title')->trim()->value() : $block->titulo,
                'configuracion' => [...$configuration, 'document' => $normalized],
            ]);
            $this->saveProperties($block, $request, $detached);
            $this->auditDesign($block, $actor, $request, count(array_unique($used)));
        });
    }

    /** @param array<string, mixed> $node */
    private static function hasContent(array $node): bool
    {
        if (in_array($node['type'] ?? null, ['text', 'variable', 'field', 'column', 'table'], true)) {
            return true;
        }
        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child) && self::hasContent($child)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $detached */
    private function saveProperties(TemplateBlock $block, Request $request, array $detached): void
    {
        foreach ($request->input('properties', []) as $property) {
            $attributes = ['etiqueta' => $property['label'] ?? '', 'ayuda' => $property['help']];
            if (array_key_exists('ai_enabled', $property)) {
                // Un campo retirado conserva su configuración para una futura restauración,
                // pero no vuelve a habilitarse en el formulario actual.
                if (isset($detached[$property['key']])) {
                    $detached[$property['key']]['ia_habilitada'] = $property['ai_enabled'];
                } else {
                    $attributes['ia_habilitada'] = $property['ai_enabled'];
                }
            }
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

    /** @param array<string, mixed> $document
     * @param  list<array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private function applyPropertyLabels(array $document, array $properties): array
    {
        $labels = [];
        foreach ($properties as $property) {
            $labels[$property['key']] = trim($property['label']);
        }
        $visit = function (array $node) use (&$visit, $labels): array {
            if (in_array($node['type'] ?? null, ['field', 'column'], true)
                && isset($labels[$node['attrs']['key'] ?? null])) {
                $node['attrs']['label'] = $labels[$node['attrs']['key']];
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
            correlationId: $request->attributes->getString('correlation_id') ?: null,
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
