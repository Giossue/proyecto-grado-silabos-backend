<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\Blocks\BlockDefinition;
use App\Modules\Configuration\Domain\Blocks\RepeatableTable;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\ResponseCondition;
use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type DraftField array{key: string, label: string, quien_llena: string, content: array<string, mixed>, show_if?: array{response: string, options: list<string>}}
 * @phpstan-type DraftSection array{key: string, title: string, orientation: string, fields: list<DraftField>}
 */
final class TemplateDraft
{
    public const MAX_SECTIONS = 60;

    public const MAX_FIELDS = 60;

    public const MAX_TEXT = 180;

    public const MAX_HTML = 100000;

    public const CELL_COLORS = ['#C3D4EA', '#4F81BD'];

    public const TEXT_COLORS = ['#000000', '#365F91', '#FFFFFF'];

    public const START = '(inicio)';

    public const END = '(final)';

    public const RESPONSIBLE_LABELS = [
        FieldDefinition::CAREER => 'Carrera',
        FieldDefinition::INSTITUTIONAL => 'Institucional',
    ];

    /** @var list<DraftSection> */
    private array $sections;

    /** @var array<string, array<string, mixed>> */
    private array $tables = [];

    private ?TemplateDefinition $withTables = null;

    /**
     * @param  list<DraftSection>  $base
     * @param  list<DraftSection>  $start
     */
    private function __construct(
        private readonly TemplateDefinition $definition,
        private readonly array $base,
        private readonly string $baseFingerprint,
        array $start,
    ) {
        $this->sections = $start;
    }

    /**
     * @param  list<DraftSection>|null  $start
     */
    public static function fromTemplate(SyllabusTemplate $template, ?array $start = null): self
    {
        $sections = self::sectionsOf($template);

        return new self($template->definition(), $sections, self::fingerprintOf($template->definition(), $sections), $start ?? $sections);
    }

    /**
     * @param  list<DraftSection>  $sections
     */
    public static function fromDraft(SyllabusTemplate $template, array $sections): self
    {
        return new self($template->definition(), $sections, self::fingerprintOf($template->definition(), self::sectionsOf($template)), $sections);
    }

    public static function fingerprint(SyllabusTemplate $template): string
    {
        return self::fingerprintOf($template->definition(), self::sectionsOf($template));
    }

    public function baseFingerprint(): string
    {
        return $this->baseFingerprint;
    }

    public function definition(): TemplateDefinition
    {
        if ($this->tables === []) {
            return $this->definition;
        }

        return $this->withTables ??= $this->definition->withBlocks($this->tables);
    }

    public function changed(): bool
    {
        return $this->sections !== $this->base || $this->tables !== [];
    }

    /**
     * @return array{version: int, sections: list<array{key: string, title: string, orientation: string, fields: list<DraftField>}>, block_changes?: array<string, array<string, mixed>>}
     */
    public function payload(): array
    {
        return ['version' => 1, 'sections' => $this->sections, ...($this->tables === [] ? [] : ['block_changes' => $this->tables])];
    }

    /**
     * @param  array<string, mixed>  $design
     */
    public function createTable(array $design): string
    {
        $design = RepeatableTable::withKeys([...$design, 'kind' => null], array_keys($this->definition()->blocks()));
        $kind = (string) $design['kind'];
        try {
            $table = BlockDefinition::define($kind, RepeatableTable::build($design), array_keys($this->definition->variables()));
        } catch (ValidationException $exception) {
            throw new TemplateDraftError('La tabla no es válida: '.implode(' ', array_map('strval', collect($exception->errors())->flatten()->all())));
        }
        $this->tables[$kind] = $table;
        $this->withTables = null;

        return $kind;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $required = $this->definition()->requiredSections();
        $library = $this->definition()->librarySections();
        $overview = [];
        foreach ($this->sections as $index => $section) {
            $overview[] = [
                'posicion' => $index + 1,
                'clave' => $section['key'],
                'titulo' => $section['title'],
                'orientacion' => $section['orientation'] === 'landscape' ? 'horizontal' : 'vertical',
                'obligatoria' => in_array($section['key'], $required, true),
                'revisa_biblioteca' => in_array($section['key'], $library, true),
                'campos' => array_map(fn (array $field, int $position): array => [
                    'posicion' => $position + 1,
                    'clave' => $field['key'],
                    'nombre' => $field['label'],
                    'quien_llena' => $field['quien_llena'],
                    'resumen' => $this->summary($field['content']),
                    ...(isset($field['show_if']) ? ['mostrar_solo_si' => $field['show_if']] : []),
                ], $section['fields'], array_keys($section['fields'])),
            ];
        }

        return $overview;
    }

    /**
     * @return array<string, mixed>
     */
    public function field(string $key): array
    {
        [$sectionIndex, $fieldIndex] = $this->locateField($key);
        $field = $this->sections[$sectionIndex]['fields'][$fieldIndex];

        return [
            'clave' => $field['key'],
            'nombre' => $field['label'],
            'quien_llena' => $field['quien_llena'],
            'seccion' => $this->sections[$sectionIndex]['key'],
            'contenido_html' => implode("\n", DocumentHtml::toLines($field['content'])),
            ...(isset($field['show_if']) ? ['mostrar_solo_si' => $field['show_if']] : []),
        ];
    }

    /**
     * @param  list<string>  $options
     */
    public function showFieldIf(string $key, string $response, array $options): string
    {
        [$sectionIndex, $fieldIndex] = $this->locateField($key);
        $response = trim($response);
        if ($response === '') {
            $this->changeField($sectionIndex, $fieldIndex, function (array $field): array {
                unset($field['show_if']);

                return $field;
            });

            return $key;
        }
        $source = null;
        foreach ($this->sections as $section) {
            foreach ($section['fields'] as $field) {
                foreach (ResponseCondition::responses($field['content']) as $attrs) {
                    if (($attrs[ResponseCondition::ID] ?? null) === $response) {
                        $source ??= $attrs;
                    }
                }
            }
        }
        if ($source === null) {
            throw new TemplateDraftError("No hay ninguna respuesta con el identificador «{$response}». Póngale \"id\" en data-response al colocarla (ver_campo muestra los identificadores).");
        }
        $keys = [];
        foreach ($options as $option) {
            $match = array_values(array_filter(is_array($source['options'] ?? null) ? $source['options'] : [], fn (array $choice): bool => $choice['const'] === trim($option) || mb_strtolower($choice['title']) === mb_strtolower(trim($option))));
            if ($match === []) {
                throw new TemplateDraftError("La respuesta «{$response}» no tiene la opción «{$option}».");
            }
            $keys[] = $match[0]['const'];
        }
        try {
            $condition = ResponseCondition::define(['response' => $response, 'options' => $keys]);
        } catch (ValidationException $exception) {
            throw new TemplateDraftError((string) collect($exception->errors())->flatten()->first());
        }
        $before = $this->sections;
        $this->changeField($sectionIndex, $fieldIndex, fn (array $field): array => [...$field, 'show_if' => $condition]);
        $fields = [];
        foreach ($this->sections as $index => $section) {
            foreach ($section['fields'] as $position => $field) {
                $fields[] = ['path' => "sections.{$index}.fields.{$position}", 'document' => $field['content'], 'show_if' => $field['show_if'] ?? null];
            }
        }
        foreach (ResponseCondition::problems($fields) as $problem) {
            if ($problem['path'] === "sections.{$sectionIndex}.fields.{$fieldIndex}.show_if") {
                $this->sections = $before;
                throw new TemplateDraftError($problem['message']);
            }
        }

        return $key;
    }

    public function addSection(string $title, string $orientation, int $position): string
    {
        if (count($this->sections) >= self::MAX_SECTIONS) {
            throw new TemplateDraftError('La plantilla ya tiene el máximo de '.self::MAX_SECTIONS.' secciones.');
        }
        $title = $this->text($title, 'El título de la sección', false);
        $key = $this->uniqueKey($title, 'seccion', $this->sectionKeys());
        array_splice($this->sections, $this->insertionIndex($position, count($this->sections)), 0, [[
            'key' => $key, 'title' => $title, 'orientation' => $this->orientation($orientation), 'fields' => [],
        ]]);

        return $key;
    }

    public function addSectionAfter(string $title, string $orientation, string $after): string
    {
        return $this->addSection($title, $orientation, $this->positionAfter(array_column($this->sections, 'key'), $after, null, 'sección'));
    }

    public function moveSectionAfter(string $key, string $after): string
    {
        $this->locateSection($key);

        return $this->moveSection($key, $this->positionAfter(array_column($this->sections, 'key'), $after, $key, 'sección'));
    }

    public function addFieldAfter(string $sectionKey, string $label, string $responsible, string $html, string $after): string
    {
        $keys = array_column($this->sections[$this->locateSection($sectionKey)]['fields'], 'key');

        return $this->addField($sectionKey, $label, $responsible, $html, $this->positionAfter($keys, $after, null, 'campo'));
    }

    public function moveFieldAfter(string $key, string $sectionKey, string $after): string
    {
        $this->locateField($key);
        $keys = array_column($this->sections[$this->locateSection($sectionKey)]['fields'], 'key');

        return $this->moveField($key, $sectionKey, $this->positionAfter($keys, $after, $key, 'campo'));
    }

    /**
     * @return list<string>
     */
    public function renumber(): array
    {
        $renamed = [];
        $number = 0;
        foreach ($this->sections as $index => $section) {
            if (preg_match('/^\s*\d+\s*\.\s*(.*)$/u', $section['title'], $match) !== 1) {
                continue;
            }
            $number++;
            $title = "{$number}. {$match[1]}";
            if ($title !== $section['title']) {
                $this->changeSection($index, fn (array $item): array => array_replace($item, ['title' => $title]));
                $renamed[] = "«{$section['title']}» → «{$title}»";
            }
            $sub = 0;
            foreach ($section['fields'] as $fieldIndex => $field) {
                if (preg_match('/^\s*\d+\s*\.\s*\d+\.?\s+(.*)$/u', $field['label'], $fieldMatch) !== 1) {
                    continue;
                }
                $sub++;
                $label = "{$number}.{$sub} {$fieldMatch[1]}";
                if ($label !== $field['label']) {
                    $this->changeField($index, $fieldIndex, fn (array $item): array => array_replace($item, ['label' => $label]));
                    $renamed[] = "«{$field['label']}» → «{$label}»";
                }
            }
        }

        return $renamed;
    }

    public function renameSection(string $key, string $title): string
    {
        $index = $this->locateSection($key);
        $title = $this->text($title, 'El título de la sección', false);
        $this->changeSection($index, fn (array $section): array => array_replace($section, ['title' => $title]));

        return $key;
    }

    public function orientSection(string $key, string $orientation): string
    {
        $index = $this->locateSection($key);
        $orientation = $this->orientation($orientation);
        $this->changeSection($index, fn (array $section): array => array_replace($section, ['orientation' => $orientation]));

        return $key;
    }

    public function moveSection(string $key, int $position): string
    {
        $index = $this->locateSection($key);
        $section = $this->sections[$index];
        array_splice($this->sections, $index, 1);
        array_splice($this->sections, $this->insertionIndex($position, count($this->sections)), 0, [$section]);

        return $key;
    }

    public function removeSection(string $key): string
    {
        $index = $this->locateSection($key);
        if (in_array($key, $this->definition()->requiredSections(), true) || in_array($key, $this->definition()->librarySections(), true)) {
            throw new TemplateDraftError("La sección «{$this->sections[$index]['title']}» es obligatoria y no se puede quitar.");
        }
        array_splice($this->sections, $index, 1);

        return $key;
    }

    public function addField(string $sectionKey, string $label, string $responsible, string $html, int $position): string
    {
        $index = $this->locateSection($sectionKey);
        if (count($this->sections[$index]['fields']) >= self::MAX_FIELDS) {
            throw new TemplateDraftError('La sección ya tiene el máximo de '.self::MAX_FIELDS.' campos.');
        }
        $label = $this->text($label, 'El nombre del campo', true);
        $key = $this->uniqueKey($label, 'campo', $this->fieldKeys());
        $field = [
            'key' => $key,
            'label' => $label,
            'quien_llena' => $this->responsible($responsible),
            'content' => $this->content($html),
        ];
        $fields = $this->sections[$index]['fields'];
        array_splice($fields, $this->insertionIndex($position, count($fields)), 0, [$field]);
        $this->setFields($index, $fields);

        return $key;
    }

    public function renameField(string $key, string $label): string
    {
        [$sectionIndex, $fieldIndex] = $this->locateField($key);
        $label = $this->text($label, 'El nombre del campo', true);
        $this->changeField($sectionIndex, $fieldIndex, fn (array $field): array => array_replace($field, ['label' => $label]));

        return $key;
    }

    public function assignField(string $key, string $responsible): string
    {
        [$sectionIndex, $fieldIndex] = $this->locateField($key);
        $responsible = $this->responsible($responsible);
        $this->changeField($sectionIndex, $fieldIndex, fn (array $field): array => array_replace($field, ['quien_llena' => $responsible]));

        return $key;
    }

    public function rewriteField(string $key, string $html): string
    {
        [$sectionIndex, $fieldIndex] = $this->locateField($key);
        $content = $this->content($html);
        $this->changeField($sectionIndex, $fieldIndex, fn (array $field): array => array_replace($field, ['content' => $content]));

        return $key;
    }

    public function moveField(string $key, string $sectionKey, int $position): string
    {
        [$sectionIndex, $fieldIndex] = $this->locateField($key);
        $target = $this->locateSection($sectionKey);
        if ($target !== $sectionIndex && count($this->sections[$target]['fields']) >= self::MAX_FIELDS) {
            throw new TemplateDraftError('La sección de destino ya tiene el máximo de '.self::MAX_FIELDS.' campos.');
        }
        $field = $this->sections[$sectionIndex]['fields'][$fieldIndex];
        $fields = $this->sections[$sectionIndex]['fields'];
        array_splice($fields, $fieldIndex, 1);
        $this->setFields($sectionIndex, $fields);
        $fields = $this->sections[$target]['fields'];
        array_splice($fields, $this->insertionIndex($position, count($fields)), 0, [$field]);
        $this->setFields($target, $fields);

        return $key;
    }

    public function removeField(string $key): string
    {
        [$sectionIndex, $fieldIndex] = $this->locateField($key);
        $fields = $this->sections[$sectionIndex]['fields'];
        array_splice($fields, $fieldIndex, 1);
        $this->setFields($sectionIndex, $fields);

        return $key;
    }

    public function applyPreset(string $key, string $presetKey): string
    {
        [$sectionIndex, $fieldIndex] = $this->locateField($key);
        $preset = collect($this->definition()->presets())->firstWhere('key', $presetKey);
        if (! is_array($preset)) {
            throw new TemplateDraftError("No existe el contenido listo «{$presetKey}». Use listar_presets para ver los disponibles.");
        }
        $this->changeField($sectionIndex, $fieldIndex, fn (array $field): array => array_replace($field, ['quien_llena' => (string) $preset['quien_llena'], 'content' => $preset['content']]));

        return $key;
    }

    /**
     * @return list<string>
     */
    public function changes(): array
    {
        $changes = array_values(array_map(fn (array $table): string => "Nueva tabla repetible «{$table['label']}».", $this->tables));
        $baseSections = $this->keyed($this->base);
        $draftSections = $this->keyed($this->sections);
        foreach ($this->sections as $section) {
            $before = $baseSections[$section['key']] ?? null;
            if ($before === null) {
                $changes[] = "Nueva sección «{$section['title']}».";

                continue;
            }
            if ($before['title'] !== $section['title']) {
                $changes[] = "La sección «{$before['title']}» se llamará «{$section['title']}».";
            }
            if ($before['orientation'] !== $section['orientation']) {
                $changes[] = "La sección «{$section['title']}» pasa a ".($section['orientation'] === 'landscape' ? 'horizontal' : 'vertical').'.';
            }
        }
        foreach ($this->base as $section) {
            if (! isset($draftSections[$section['key']])) {
                $changes[] = "Se quita la sección «{$section['title']}».";
            }
        }
        $common = fn (array $sections, array $other): array => array_values(array_filter(array_column($sections, 'key'), fn (string $key): bool => isset($other[$key])));
        if ($common($this->base, $draftSections) !== $common($this->sections, $baseSections)) {
            $changes[] = 'Cambia el orden de las secciones.';
        }

        $baseFields = $this->fieldsByKey($this->base);
        $draftFields = $this->fieldsByKey($this->sections);
        foreach ($draftFields as $key => $field) {
            $name = $this->fieldName($field['field']);
            $before = $baseFields[$key] ?? null;
            if ($before === null) {
                $changes[] = "Nuevo campo «{$name}» en «{$field['section']['title']}».";

                continue;
            }
            if ($before['field']['label'] !== $field['field']['label']) {
                $changes[] = "El campo «{$this->fieldName($before['field'])}» se llamará «{$name}».";
            }
            if ($before['field']['quien_llena'] !== $field['field']['quien_llena']) {
                $changes[] = "«{$name}» ahora lo llena: ".self::RESPONSIBLE_LABELS[$field['field']['quien_llena']].'.';
            }
            if ($before['field']['content'] !== $field['field']['content']) {
                $changes[] = "Cambia el contenido de «{$name}».";
            }
            if (($before['field']['show_if'] ?? null) !== ($field['field']['show_if'] ?? null)) {
                $changes[] = isset($field['field']['show_if'])
                    ? "«{$name}» se mostrará solo si la respuesta «{$field['field']['show_if']['response']}» tiene: ".implode(', ', $field['field']['show_if']['options']).'.'
                    : "«{$name}» se mostrará siempre.";
            }
            if ($before['section']['key'] !== $field['section']['key']) {
                $changes[] = "«{$name}» pasa a la sección «{$field['section']['title']}».";
            }
        }
        foreach ($baseFields as $key => $field) {
            if (! isset($draftFields[$key]) && isset($draftSections[$field['section']['key']])) {
                $changes[] = "Se quita el campo «{$this->fieldName($field['field'])}» de «{$field['section']['title']}».";
            }
        }
        foreach ($this->sections as $section) {
            $before = $baseSections[$section['key']] ?? null;
            if ($before === null) {
                continue;
            }
            $kept = fn (array $fields, array $other): array => array_values(array_filter(array_column($fields, 'key'), fn (string $key): bool => in_array($key, array_column($other, 'key'), true)));
            if ($kept($before['fields'], $section['fields']) !== $kept($section['fields'], $before['fields'])) {
                $changes[] = "Cambia el orden de los campos de «{$section['title']}».";
            }
        }

        return $changes;
    }

    /**
     * @return array{secciones: list<string>, campos: list<string>}
     */
    public function changedKeys(): array
    {
        $baseSections = $this->keyed($this->base);
        $baseFields = $this->fieldsByKey($this->base);
        $sections = [];
        foreach ($this->sections as $section) {
            $before = $baseSections[$section['key']] ?? null;
            if ($before === null || $before['title'] !== $section['title'] || $before['orientation'] !== $section['orientation']) {
                $sections[] = $section['key'];
            }
        }
        $fields = [];
        foreach ($this->fieldsByKey($this->sections) as $key => $field) {
            $before = $baseFields[$key] ?? null;
            if ($before === null || $before['section']['key'] !== $field['section']['key']
                || array_diff_key($field['field'], ['content' => true]) !== array_diff_key($before['field'], ['content' => true])
                || $before['field']['content'] !== $field['field']['content']) {
                $fields[] = $key;
            }
        }

        return ['secciones' => $sections, 'campos' => $fields];
    }

    /**
     * @return list<DraftSection>
     */
    private static function sectionsOf(SyllabusTemplate $template): array
    {
        $conditions = $template->definition()->fieldConditions();

        return array_values($template->sections->map(fn (TemplateSection $section): array => [
            'key' => $section->clave,
            'title' => $section->titulo,
            'orientation' => $section->orientacion === 'landscape' ? 'landscape' : 'portrait',
            'fields' => array_values($section->blocks->flatMap(fn (TemplateBlock $block) => $block->fields->map(fn (FieldDefinition $field): array => [
                'key' => $field->clave,
                'label' => $field->etiqueta,
                'quien_llena' => $field->quien_llena,
                'content' => $field->contenido,
                ...(isset($conditions[$field->clave]) ? ['show_if' => $conditions[$field->clave]] : []),
            ])->all())->all()),
        ])->all());
    }

    /**
     * @param  list<DraftSection>  $sections
     */
    private static function fingerprintOf(TemplateDefinition $definition, array $sections): string
    {
        return hash('sha256', json_encode([$definition->toArray(), $sections], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function summary(array $document): string
    {
        foreach (FreeText::blocks($document) as $node) {
            if (($node['type'] ?? null) === 'documentBlock') {
                $kind = (string) data_get($node, 'attrs.kind', '');
                $label = $this->definition()->block($kind)['label'] ?? $kind;

                return "[bloque «{$label}»]";
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', FreeText::plainText($document)) ?? '');
        if ($text === '' || trim(str_replace('|', '', $text)) === '') {
            return '(vacío: espacio para responder)';
        }

        return Str::limit($text, 160);
    }

    private function text(string $value, string $what, bool $allowEmpty): string
    {
        $value = trim($value);
        if ($value === '' && ! $allowEmpty) {
            throw new TemplateDraftError("{$what} no puede quedar vacío.");
        }
        if (mb_strlen($value) > self::MAX_TEXT) {
            throw new TemplateDraftError("{$what} admite hasta ".self::MAX_TEXT.' caracteres.');
        }

        return $value;
    }

    private function orientation(string $value): string
    {
        return match (strtolower(trim($value))) {
            'vertical', 'portrait' => 'portrait',
            'horizontal', 'landscape' => 'landscape',
            default => throw new TemplateDraftError('La orientación debe ser «vertical» u «horizontal».'),
        };
    }

    private function responsible(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) FieldDefinition::responsible($value);
        if (! in_array($value, FieldDefinition::RESPONSIBLES, true)) {
            throw new TemplateDraftError('quien_llena debe ser «carrera» o «institucional».');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $html): array
    {
        $html = trim($html);
        if (mb_strlen($html) > self::MAX_HTML) {
            throw new TemplateDraftError('El contenido es demasiado largo.');
        }
        try {
            $document = app(TemplateContext::class)->using(
                $this->definition(),
                fn (): array => FieldDocument::normalize(DocumentHtml::fromHtml($html === '' ? '<p></p>' : $html), 'contenido_html'),
            );
        } catch (ValidationException $exception) {
            throw new TemplateDraftError('El contenido no es válido: '.implode(' ', array_map('strval', collect($exception->errors())->flatten()->all())));
        }
        $wrong = array_values(array_unique($this->outsidePalette($document)));
        if ($wrong !== []) {
            throw new TemplateDraftError('Colores no permitidos: '.implode(', ', $wrong).'. Fondo de celda: celeste #C3D4EA (con negrita) o azul institucional #4F81BD (con texto blanco #FFFFFF y negrita). Texto: negro #000000, azul #365F91 o blanco #FFFFFF.');
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function outsidePalette(array $node): array
    {
        $wrong = [];
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $check = function (mixed $color, array $allowed) use (&$wrong): void {
            if (is_string($color) && ! in_array(strtoupper($color), $allowed, true)) {
                $wrong[] = strtoupper($color);
            }
        };
        $check($attrs['backgroundColor'] ?? null, self::CELL_COLORS);
        $check($attrs['textColor'] ?? null, self::TEXT_COLORS);
        foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
            if (is_array($mark) && ($mark['type'] ?? null) === 'textStyle') {
                $check(data_get($mark, 'attrs.color'), self::TEXT_COLORS);
            }
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                array_push($wrong, ...$this->outsidePalette($child));
            }
        }

        return $wrong;
    }

    /**
     * @param  callable(DraftSection): DraftSection  $change
     */
    private function changeSection(int $index, callable $change): void
    {
        $this->sections = array_map(
            fn (array $section, int $position): array => $position === $index ? $change($section) : $section,
            $this->sections,
            array_keys($this->sections),
        );
    }

    /**
     * @param  callable(DraftField): DraftField  $change
     */
    private function changeField(int $sectionIndex, int $fieldIndex, callable $change): void
    {
        $this->changeSection($sectionIndex, fn (array $section): array => array_replace($section, ['fields' => array_map(
            fn (array $field, int $position): array => $position === $fieldIndex ? $change($field) : $field,
            $section['fields'],
            array_keys($section['fields']),
        )]));
    }

    /**
     * @param  array<int, DraftField>  $fields
     */
    private function setFields(int $sectionIndex, array $fields): void
    {
        $this->changeSection($sectionIndex, fn (array $section): array => array_replace($section, ['fields' => array_values($fields)]));
    }

    /**
     * @param  list<string>  $keys
     */
    private function positionAfter(array $keys, string $after, ?string $moving, string $what): int
    {
        $keys = array_values(array_filter($keys, fn (string $key): bool => $key !== $moving));
        $after = trim($after);
        if ($after === self::START) {
            return 1;
        }
        if ($after === self::END || $after === '') {
            return count($keys) + 1;
        }
        if ($after === $moving) {
            throw new TemplateDraftError("No se puede ubicar un {$what} después de sí mismo.");
        }
        $index = array_search($after, $keys, true);
        if ($index === false) {
            throw new TemplateDraftError("No existe el {$what} «{$after}» en ese lugar. Use una clave de ver_plantilla, «".self::START.'» o «'.self::END.'».');
        }

        return $index + 2;
    }

    private function insertionIndex(int $position, int $count): int
    {
        return $position < 1 || $position > $count ? $count : $position - 1;
    }

    private function locateSection(string $key): int
    {
        foreach ($this->sections as $index => $section) {
            if ($section['key'] === $key) {
                return $index;
            }
        }
        throw new TemplateDraftError("No existe la sección «{$key}». Use ver_plantilla para ver las claves.");
    }

    /**
     * @return array{int, int}
     */
    private function locateField(string $key): array
    {
        foreach ($this->sections as $sectionIndex => $section) {
            foreach ($section['fields'] as $fieldIndex => $field) {
                if ($field['key'] === $key) {
                    return [$sectionIndex, $fieldIndex];
                }
            }
        }
        throw new TemplateDraftError("No existe el campo «{$key}». Use ver_plantilla para ver las claves.");
    }

    /**
     * @return list<string>
     */
    private function sectionKeys(): array
    {
        return array_values(array_unique([...array_column($this->base, 'key'), ...array_column($this->sections, 'key')]));
    }

    /**
     * @return list<string>
     */
    private function fieldKeys(): array
    {
        return array_keys([...$this->fieldsByKey($this->base), ...$this->fieldsByKey($this->sections)]);
    }

    /**
     * @param  list<string>  $used
     */
    private function uniqueKey(string $name, string $fallback, array $used): string
    {
        $base = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->replaceMatches('/^[0-9_]+/', '')->limit(80, '')->rtrim('_')->toString();
        $base = $base === '' ? $fallback : $base;
        $key = $base;
        for ($n = 2; in_array($key, $used, true); $n++) {
            $key = "{$base}_{$n}";
        }

        return $key;
    }

    /**
     * @param  list<DraftSection>  $sections
     * @return array<string, DraftSection>
     */
    private function keyed(array $sections): array
    {
        return array_column($sections, null, 'key');
    }

    /**
     * @param  list<DraftSection>  $sections
     * @return array<string, array{section: DraftSection, field: DraftField}>
     */
    private function fieldsByKey(array $sections): array
    {
        $fields = [];
        foreach ($sections as $section) {
            foreach ($section['fields'] as $field) {
                $fields[$field['key']] = ['section' => $section, 'field' => $field];
            }
        }

        return $fields;
    }

    /**
     * @param  DraftField  $field
     */
    private function fieldName(array $field): string
    {
        return $field['label'] !== '' ? $field['label'] : $field['key'];
    }
}
