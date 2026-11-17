<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

use App\Modules\AiAssistance\Domain\Data\AiToolCall;
use App\Modules\AiAssistance\Domain\Data\AiToolSpec;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Application\TemplateVersions;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateVersion;
use stdClass;

final class TemplateTools
{
    public const MAX_MESSAGE = 4000;

    private const CELL_TYPES = ['texto' => 'text', 'opcion' => 'choice', 'numero' => 'number'];

    private const READ_ONLY = ['ver_plantilla', 'ver_campo', 'listar_presets', 'listar_variables', 'ver_estado', 'ver_historial', 'comparar_version', 'responder', 'preguntar'];

    private const HISTORY_LIMIT = 30;

    private const CHANGES_LIMIT = 60;

    /**
     * @param  array{estado: string, aviso: string}  $process
     * @param  list<string>  $preexisting
     */
    public function __construct(
        private readonly TemplateDraft $draft,
        private readonly TemplateDraftValidator $validator,
        private readonly array $process,
        private readonly array $preexisting = [],
        private readonly ?SyllabusTemplate $template = null,
    ) {}

    public function locked(): bool
    {
        return $this->process['estado'] === 'abierta';
    }

    /**
     * @return list<AiToolSpec>
     */
    public function specs(): array
    {
        $key = ['type' => 'string', 'description' => 'Clave tal como aparece en ver_plantilla.'];
        $after = ['type' => 'string', 'description' => 'Clave tras la cual va, según ver_plantilla. Use «(inicio)» para el principio o «(final)» para el final.'];
        $orientation = ['type' => 'string', 'enum' => ['vertical', 'horizontal']];
        $responsible = ['type' => 'string', 'enum' => FieldDefinition::RESPONSIBLES, 'description' => 'carrera: lo escriben la carrera en el proyecto de carrera de cada asignatura y el docente en los espacios que le dejen (Carrera); institucional: texto de la plantilla, igual para todos (Institucional).'];
        $html = ['type' => 'string', 'description' => 'Contenido en HTML permitido. <p></p> deja un espacio vacío para responder.'];
        $specs = [
            $this->spec('ver_plantilla', 'Muestra las secciones y campos del borrador en orden, con su clave, nombre, quién llena cada campo y un resumen del contenido.', []),
            $this->spec('ver_campo', 'Muestra el contenido completo de un campo como HTML.', ['clave' => $key]),
            $this->spec('listar_presets', 'Lista los contenidos listos (tablas y bloques oficiales) que se ponen en un campo con usar_preset.', []),
            $this->spec('listar_variables', 'Lista los datos del sistema que se insertan con <span data-variable="clave"></span>.', []),
            $this->spec('ver_historial', 'Lista las versiones guardadas de la plantilla (cada guardado es una versión), de la más nueva a la más vieja, con su número, fecha y tamaño. Solo lectura.', []),
            $this->spec('comparar_version', 'Lista qué cambió desde una versión guardada hasta la plantilla guardada ahora. Solo lectura: restaurar se hace en el editor.', ['numero' => ['type' => 'integer', 'description' => 'Número de la versión según ver_historial.']]),
            $this->spec('ver_estado', 'Indica si la convocatoria está abierta, en pausa o no hay, y qué implica cambiar la plantilla.', []),
            $this->spec('agregar_seccion', 'Agrega una sección vacía después de otra sección y devuelve su clave. Después agréguele al menos un campo.', ['titulo' => ['type' => 'string'], 'orientacion' => $orientation, 'despues_de' => $after]),
            $this->spec('renombrar_seccion', 'Cambia el título de una sección.', ['clave' => $key, 'titulo' => ['type' => 'string']]),
            $this->spec('cambiar_orientacion_seccion', 'Cambia la orientación de la hoja de una sección.', ['clave' => $key, 'orientacion' => $orientation]),
            $this->spec('mover_seccion', 'Mueve una sección para que quede justo después de otra.', ['clave' => $key, 'despues_de' => $after]),
            $this->spec('renumerar', 'Vuelve a numerar en orden las secciones que llevan número («7. …») y los subtítulos numerados de sus campos («7.1 …»). Úsela después de agregar, mover o quitar secciones numeradas.', []),
            $this->spec('quitar_seccion', 'Quita una sección con todos sus campos. Las secciones obligatorias no se pueden quitar.', ['clave' => $key]),
            $this->spec('agregar_campo', 'Agrega un campo a una sección y devuelve su clave.', ['seccion' => $key, 'nombre' => ['type' => 'string', 'description' => 'Subtítulo del campo. Vacío si no lleva subtítulo.'], 'quien_llena' => $responsible, 'contenido_html' => $html, 'despues_de' => $after]),
            $this->spec('renombrar_campo', 'Cambia el nombre (subtítulo) de un campo. Vacío quita el subtítulo.', ['clave' => $key, 'nombre' => ['type' => 'string']]),
            $this->spec('cambiar_responsable_campo', 'Cambia quién llena un campo.', ['clave' => $key, 'quien_llena' => $responsible]),
            $this->spec('cambiar_contenido_campo', 'Reemplaza todo el contenido de un campo. Lea antes el contenido con ver_campo.', ['clave' => $key, 'contenido_html' => $html]),
            $this->spec('mover_campo', 'Mueve un campo para que quede después de otro campo, en la misma sección o en otra.', ['clave' => $key, 'seccion' => $key, 'despues_de' => $after]),
            $this->spec('quitar_campo', 'Quita un campo.', ['clave' => $key]),
            $this->spec('duplicar_campo', 'Copia un campo justo debajo de él, con clave nueva, el nombre seguido de «(copia)» y el mismo contenido y responsable; las respuestas copiadas reciben identificadores nuevos. Devuelve la clave de la copia.', ['clave' => $key]),
            $this->spec('duplicar_seccion', 'Copia una sección con todos sus campos justo debajo de ella, con claves nuevas y el título seguido de «(copia)». Devuelve la clave de la copia.', ['clave' => $key]),
            $this->spec('usar_preset', 'Reemplaza el contenido de un campo por un contenido listo y fija quién lo llena.', ['clave' => $key, 'preset' => ['type' => 'string', 'description' => 'Clave del contenido listo según listar_presets.']]),
            $this->spec('crear_tabla_repetible', 'Crea una tabla repetible nueva (el docente agrega filas) y devuelve su clave. Después póngala en un campo Carrera con <div data-block="CLAVE"></div> (agregar_campo o cambiar_contenido_campo).', [
                'titulo' => ['type' => 'string', 'description' => 'Título de la tabla, por ejemplo «Prácticas de laboratorio».'],
                'fila' => ['type' => 'string', 'description' => 'Nombre de cada fila en singular, por ejemplo «práctica»; el botón dice «Agregar práctica».'],
                'minimo' => ['type' => 'integer', 'description' => 'Filas mínimas (0 a 100).'],
                'maximo' => ['type' => 'integer', 'description' => 'Filas máximas (1 a 100).'],
                'al_empezar' => ['type' => 'integer', 'description' => 'Filas con las que empieza, entre el mínimo y el máximo.'],
                'columnas' => ['type' => 'array', 'description' => 'Columnas en orden (1 a 24).', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'nombre' => ['type' => 'string'],
                        'tipo' => ['type' => 'string', 'enum' => array_keys(self::CELL_TYPES), 'description' => 'texto (hasta 1000 caracteres), opcion (elegir una de «opciones») o numero (hasta 999,99).'],
                        'obligatoria' => ['type' => 'boolean', 'description' => 'Si es verdadera, el docente no puede enviar con esa celda vacía en alguna fila.'],
                        'opciones' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Solo para «opcion»; vacío en los otros tipos.'],
                    ],
                    'required' => ['nombre', 'tipo', 'obligatoria', 'opciones'],
                    'additionalProperties' => false,
                ]],
            ]),
            $this->spec('mostrar_solo_si', 'Muestra un campo solo si una respuesta anterior de elegir una o varias tiene alguna de las opciones dadas (si no, el docente no lo ve, no se le exige y no se imprime). Con «respuesta» vacía quita la condición. Para una sola respuesta use "showIf" en su data-response.', [
                'clave' => $key,
                'respuesta' => ['type' => 'string', 'description' => 'Identificador ("id" en data-response) de la respuesta anterior, por ejemplo «usa_laboratorio». Vacío quita la condición.'],
                'opciones' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Opciones de esa respuesta que muestran el campo, por su texto («Sí») o su clave («o1»). Vacío si quita la condición.'],
            ]),
            $this->spec('validar_borrador', 'Revisa el borrador con las mismas reglas de guardar y lista los cambios hechos.', []),
            $this->spec('responder', 'Termina el turno con un mensaje para el administrador: resume los cambios del borrador o responde su pregunta.', ['mensaje' => ['type' => 'string']]),
            $this->spec('preguntar', 'Termina el turno pidiendo una aclaración cuando el pedido es ambiguo.', ['pregunta' => ['type' => 'string']]),
        ];

        return $this->locked()
            ? array_values(array_filter($specs, fn (AiToolSpec $spec): bool => in_array($spec->name, self::READ_ONLY, true)))
            : $specs;
    }

    public function execute(AiToolCall $call): TemplateToolResult
    {
        if ($call->arguments === null) {
            return TemplateToolResult::error('Los argumentos no son un objeto JSON válido.');
        }
        if ($this->locked() && ! in_array($call->name, self::READ_ONLY, true)) {
            return TemplateToolResult::error('La convocatoria está abierta: no se pueden proponer cambios a la plantilla.');
        }
        $args = $call->arguments;
        try {
            return match ($call->name) {
                'ver_plantilla' => TemplateToolResult::ok(['secciones' => $this->draft->overview()]),
                'ver_campo' => TemplateToolResult::ok($this->draft->field($this->string($args, 'clave'))),
                'listar_presets' => TemplateToolResult::ok(['presets' => array_map(fn (array $preset): array => [
                    'preset' => $preset['key'], 'nombre' => $preset['label'], 'quien_llena' => $preset['quien_llena'],
                ], $this->draft->definition()->presets())]),
                'listar_variables' => TemplateToolResult::ok(['variables' => array_map(fn (array $variable): array => [
                    'clave' => $variable['key'], 'nombre' => $variable['label'],
                ], TemplateVariables::catalog())]),
                'ver_estado' => TemplateToolResult::ok($this->process),
                'ver_historial' => TemplateToolResult::ok($this->versions()),
                'comparar_version' => TemplateToolResult::ok($this->compare($args['numero'] ?? null)),
                'agregar_seccion' => $this->apply('Sección agregada.', fn (): string => $this->draft->addSectionAfter($this->string($args, 'titulo'), $this->string($args, 'orientacion'), $this->string($args, 'despues_de'))),
                'renombrar_seccion' => $this->apply('Sección renombrada.', fn (): string => $this->draft->renameSection($this->string($args, 'clave'), $this->string($args, 'titulo'))),
                'cambiar_orientacion_seccion' => $this->apply('Orientación cambiada.', fn (): string => $this->draft->orientSection($this->string($args, 'clave'), $this->string($args, 'orientacion'))),
                'mover_seccion' => $this->apply('Sección movida.', fn (): string => $this->draft->moveSectionAfter($this->string($args, 'clave'), $this->string($args, 'despues_de'))),
                'renumerar' => TemplateToolResult::ok(['ok' => true, 'renombrados' => $this->draft->renumber()]),
                'quitar_seccion' => $this->apply('Sección quitada.', fn (): string => $this->draft->removeSection($this->string($args, 'clave'))),
                'agregar_campo' => $this->apply('Campo agregado.', fn (): string => $this->draft->addFieldAfter($this->string($args, 'seccion'), $this->string($args, 'nombre'), $this->string($args, 'quien_llena'), $this->string($args, 'contenido_html'), $this->string($args, 'despues_de'))),
                'renombrar_campo' => $this->apply('Campo renombrado.', fn (): string => $this->draft->renameField($this->string($args, 'clave'), $this->string($args, 'nombre'))),
                'cambiar_responsable_campo' => $this->apply('Responsable cambiado.', fn (): string => $this->draft->assignField($this->string($args, 'clave'), $this->string($args, 'quien_llena'))),
                'cambiar_contenido_campo' => $this->apply('Contenido reemplazado.', fn (): string => $this->draft->rewriteField($this->string($args, 'clave'), $this->string($args, 'contenido_html'))),
                'mover_campo' => $this->apply('Campo movido.', fn (): string => $this->draft->moveFieldAfter($this->string($args, 'clave'), $this->string($args, 'seccion'), $this->string($args, 'despues_de'))),
                'quitar_campo' => $this->apply('Campo quitado.', fn (): string => $this->draft->removeField($this->string($args, 'clave'))),
                'duplicar_campo' => $this->apply('Campo duplicado.', fn (): string => $this->draft->duplicateField($this->string($args, 'clave'))),
                'duplicar_seccion' => $this->apply('Sección duplicada.', fn (): string => $this->draft->duplicateSection($this->string($args, 'clave'))),
                'usar_preset' => $this->apply('Contenido listo aplicado.', fn (): string => $this->draft->applyPreset($this->string($args, 'clave'), $this->string($args, 'preset'))),
                'crear_tabla_repetible' => $this->apply('Tabla repetible creada. Póngala en un campo con <div data-block="CLAVE"></div>.', fn (): string => $this->draft->createTable($this->tableDesign($args))),
                'mostrar_solo_si' => $this->apply('Condición actualizada.', fn (): string => $this->draft->showFieldIf($this->string($args, 'clave'), $this->string($args, 'respuesta'), $this->strings($args, 'opciones'))),
                'validar_borrador' => TemplateToolResult::ok($this->validation()),
                'responder' => TemplateToolResult::finish(TemplateToolResult::ANSWER, $this->message($args, 'mensaje')),
                'preguntar' => TemplateToolResult::finish(TemplateToolResult::QUESTION, $this->message($args, 'pregunta')),
                default => TemplateToolResult::error("No existe la función «{$call->name}»."),
            };
        } catch (TemplateDraftError $exception) {
            return TemplateToolResult::error($exception->getMessage());
        }
    }

    /**
     * @return array{valido: bool, errores: list<string>, cambios: list<string>}
     */
    public function validation(): array
    {
        $errors = $this->draft->changed() ? array_values(array_diff($this->validator->errors($this->draft), $this->preexisting)) : [];

        return ['valido' => $errors === [], 'errores' => $errors, 'cambios' => $this->draft->changes()];
    }

    /**
     * @return array{versiones: list<array{numero: int, guardada_en: string, secciones: int, campos: int}>, nota: string}
     */
    private function versions(): array
    {
        $versions = array_values(TemplateVersion::query()
            ->where('plantilla_id', $this->history()->id)
            ->orderByDesc('numero_version_plantilla')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(function (TemplateVersion $version): array {
                $sections = is_array($version->contenido['sections'] ?? null) ? $version->contenido['sections'] : [];

                return [
                    'numero' => $version->numero,
                    'guardada_en' => $version->guardada_en->toIso8601String(),
                    'secciones' => count($sections),
                    'campos' => array_sum(array_map(fn (mixed $section): int => is_array($section) && is_array($section['fields'] ?? null) ? count($section['fields']) : 0, $sections)),
                ];
            })
            ->all());

        return [
            'versiones' => $versions,
            'nota' => $versions === []
                ? 'Todavía no hay versiones guardadas: la primera aparece al abrir «Historial» o al guardar.'
                : 'Quién guardó cada versión se ve en «Historial» del editor.',
        ];
    }

    /**
     * @return array{version: int, cambios_hasta_la_guardada: list<string>, omitidos: int}
     */
    private function compare(mixed $number): array
    {
        if (! is_int($number)) {
            throw new TemplateDraftError('Falta el parámetro «numero» como número entero.');
        }
        $template = $this->history();
        $version = TemplateVersion::query()->where('plantilla_id', $template->id)->where('numero_version_plantilla', $number)->first();
        if ($version === null) {
            throw new TemplateDraftError("No existe la versión {$number}. Use ver_historial para ver los números.");
        }
        $changes = app(TemplateVersions::class)->compare($template, $version);

        return [
            'version' => $number,
            'cambios_hasta_la_guardada' => array_slice($changes, 0, self::CHANGES_LIMIT),
            'omitidos' => max(0, count($changes) - self::CHANGES_LIMIT),
        ];
    }

    private function history(): SyllabusTemplate
    {
        return $this->template ?? throw new TemplateDraftError('El historial no está disponible.');
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     */
    private function spec(string $name, string $description, array $properties): AiToolSpec
    {
        return new AiToolSpec($name, $description, [
            'type' => 'object',
            'properties' => $properties === [] ? new stdClass : $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ]);
    }

    /**
     * @param  callable(): string  $change
     */
    private function apply(string $message, callable $change): TemplateToolResult
    {
        return TemplateToolResult::ok(['ok' => true, 'mensaje' => $message, 'clave' => $change()]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function tableDesign(array $args): array
    {
        $columns = $args['columnas'] ?? null;
        if (! is_array($columns) || ! array_is_list($columns) || $columns === [] || count($columns) > 24) {
            throw new TemplateDraftError('Indique de 1 a 24 columnas.');
        }
        $design = ['label' => trim($this->string($args, 'titulo')), 'item' => trim($this->string($args, 'fila'))];
        if ($design['label'] === '' || mb_strlen($design['label']) > 120 || $design['item'] === '' || mb_strlen($design['item']) > 60) {
            throw new TemplateDraftError('Escriba el título (hasta 120 caracteres) y el nombre de cada fila (hasta 60).');
        }
        foreach (['minimo' => 'min', 'maximo' => 'max', 'al_empezar' => 'initial'] as $name => $key) {
            if (! is_int($args[$name] ?? null)) {
                throw new TemplateDraftError("Falta el parámetro «{$name}» como número entero.");
            }
            $design[$key] = $args[$name];
        }
        $design['columns'] = array_map(function (mixed $column): array {
            $name = is_array($column) && is_string($column['nombre'] ?? null) ? trim($column['nombre']) : '';
            $type = self::CELL_TYPES[is_array($column) ? ($column['tipo'] ?? '') : ''] ?? null;
            if ($name === '' || mb_strlen($name) > 300 || $type === null) {
                throw new TemplateDraftError('Cada columna necesita «nombre» y un «tipo»: texto, opcion o numero.');
            }
            $options = is_array($column['opciones'] ?? null) ? array_values(array_filter(array_map(fn (mixed $option): string => is_string($option) ? trim($option) : '', $column['opciones']), fn (string $option): bool => $option !== '')) : [];
            if ($type === 'choice' && ($options === [] || count($options) !== count(array_unique($options)))) {
                throw new TemplateDraftError("La columna «{$name}» es de tipo opcion: escriba sus opciones sin repetir.");
            }

            return [
                'title' => $name,
                'type' => $type,
                'required' => ($column['obligatoria'] ?? false) === true,
                'width' => null,
                ...match ($type) {
                    'choice' => ['options' => array_map(fn (string $option): array => ['label' => $option], $options)],
                    'number' => ['maximum' => 999.99, 'decimals' => 2],
                    default => ['max_length' => 1000, 'multiline' => false],
                },
            ];
        }, $columns);

        return $design;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function string(array $args, string $name): string
    {
        $value = $args[$name] ?? null;
        if (! is_string($value)) {
            throw new TemplateDraftError("Falta el parámetro «{$name}» como texto.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function strings(array $args, string $name): array
    {
        $value = $args[$name] ?? null;
        if (! is_array($value) || ! array_is_list($value) || array_filter($value, fn (mixed $item): bool => ! is_string($item)) !== []) {
            throw new TemplateDraftError("Falta el parámetro «{$name}» como lista de textos.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function message(array $args, string $name): string
    {
        $value = trim($this->string($args, $name));
        if ($value === '') {
            throw new TemplateDraftError("El parámetro «{$name}» no puede quedar vacío.");
        }

        return mb_substr($value, 0, self::MAX_MESSAGE);
    }
}
