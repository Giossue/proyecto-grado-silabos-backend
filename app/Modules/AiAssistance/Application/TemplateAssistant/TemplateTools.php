<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

use App\Modules\AiAssistance\Domain\Data\AiToolCall;
use App\Modules\AiAssistance\Domain\Data\AiToolSpec;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use stdClass;

final class TemplateTools
{
    public const MAX_MESSAGE = 4000;

    private const READ_ONLY = ['ver_plantilla', 'ver_campo', 'listar_presets', 'listar_variables', 'ver_estado', 'responder', 'preguntar'];

    /**
     * @param  array{estado: string, aviso: string}  $process
     * @param  list<string>  $preexisting
     */
    public function __construct(
        private readonly TemplateDraft $draft,
        private readonly TemplateDraftValidator $validator,
        private readonly array $process,
        private readonly array $preexisting = [],
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
        $responsible = ['type' => 'string', 'enum' => FieldDefinition::RESPONSIBLES, 'description' => 'docente: lo escribe el docente; coordinacion: viene de la ficha de la asignatura (Carrera); institucional: texto fijo de la plantilla.'];
        $html = ['type' => 'string', 'description' => 'Contenido en HTML permitido. <p></p> deja un espacio vacío para responder.'];
        $specs = [
            $this->spec('ver_plantilla', 'Muestra las secciones y campos del borrador en orden, con su clave, nombre, quién llena cada campo y un resumen del contenido.', []),
            $this->spec('ver_campo', 'Muestra el contenido completo de un campo como HTML.', ['clave' => $key]),
            $this->spec('listar_presets', 'Lista los contenidos listos (tablas y bloques oficiales) que se ponen en un campo con usar_preset.', []),
            $this->spec('listar_variables', 'Lista los datos del sistema que se insertan con <span data-variable="clave"></span>.', []),
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
            $this->spec('usar_preset', 'Reemplaza el contenido de un campo por un contenido listo y fija quién lo llena.', ['clave' => $key, 'preset' => ['type' => 'string', 'description' => 'Clave del contenido listo según listar_presets.']]),
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
                'usar_preset' => $this->apply('Contenido listo aplicado.', fn (): string => $this->draft->applyPreset($this->string($args, 'clave'), $this->string($args, 'preset'))),
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
