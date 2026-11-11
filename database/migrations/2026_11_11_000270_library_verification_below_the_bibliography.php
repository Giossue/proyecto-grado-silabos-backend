<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const FIELD = 'verificacion_bibliografia';

    private const LABEL = 'Bibliografía verificada por';

    private const LIBRARY_VARIABLES = ['firma_bibliotecario', 'nombre_bibliotecario', 'fecha_verificacion'];

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function up(): void
    {
        $this->keepingTemplateDate(function (): void {
            foreach (DB::table('plantilla_silabo')->pluck('definicion_plantilla', 'plantilla_id') as $id => $definition) {
                $this->moveDown((string) $id, $this->decode($definition));
            }
        });
    }

    public function down(): void
    {
        $this->keepingTemplateDate(function (): void {
            foreach (DB::table('plantilla_silabo')->pluck('definicion_plantilla', 'plantilla_id') as $id => $definition) {
                $this->moveBack((string) $id, $this->decode($definition));
            }
        });
    }

    private function keepingTemplateDate(callable $change): void
    {
        $dates = DB::table('plantilla_silabo')->pluck('plantilla_modificada_en', 'plantilla_id');
        $change();
        foreach ($dates as $id => $date) {
            DB::table('plantilla_silabo')->where('plantilla_id', $id)->update(['plantilla_modificada_en' => $date]);
        }
    }

    /** @param  array<string, mixed>  $definition */
    private function moveDown(string $templateId, array $definition): void
    {
        $section = $this->librarySection($templateId, $definition);
        if ($section === null || $this->libraryField($templateId) !== null) {
            return;
        }
        foreach ($this->fields($templateId) as $field) {
            $split = $this->split($this->decode($field->contenido_definicion_campo));
            if ($split === null) {
                continue;
            }
            [$signatures, $library] = $split;
            DB::table('definiciones_campo')->where('definicion_campo_id', $field->definicion_campo_id)
                ->update(['contenido_definicion_campo' => json_encode($signatures, self::JSON)]);
            $blockId = (string) Str::uuid();
            DB::table('campos_plantilla')->insert([
                'campo_plantilla_id' => $blockId,
                'seccion_plantilla_id' => $section,
                'plantilla_id' => $templateId,
                'clave_campo_plantilla' => self::FIELD,
                'titulo_campo_plantilla' => '',
                'posicion_campo_plantilla' => (int) DB::table('campos_plantilla')->where('seccion_plantilla_id', $section)->where('vigente_en_plantilla', true)->max('posicion_campo_plantilla') + 1,
            ]);
            DB::table('definiciones_campo')->insert([
                'definicion_campo_id' => (string) Str::uuid(),
                'campo_plantilla_id' => $blockId,
                'plantilla_id' => $templateId,
                'quien_llena_definicion_campo' => 'institucional',
                'contenido_definicion_campo' => json_encode($library, self::JSON),
            ]);
            $this->movePresetDown($templateId, $definition, $field->clave_campo_plantilla);

            return;
        }
    }

    /** @param  array<string, mixed>  $definition */
    private function moveBack(string $templateId, array $definition): void
    {
        $library = $this->libraryField($templateId);
        if ($library === null) {
            return;
        }
        $libraryContent = $this->decode($library->contenido_definicion_campo);
        foreach ($this->fields($templateId) as $field) {
            if ($field->definicion_campo_id === $library->definicion_campo_id) {
                continue;
            }
            $merged = $this->merge($this->decode($field->contenido_definicion_campo), $libraryContent);
            if ($merged === null) {
                continue;
            }
            DB::table('definiciones_campo')->where('definicion_campo_id', $field->definicion_campo_id)
                ->update(['contenido_definicion_campo' => json_encode($merged, self::JSON)]);
            $this->retire($library->definicion_campo_id, $library->campo_plantilla_id);
            $this->movePresetBack($templateId, $definition, $field->clave_campo_plantilla);

            return;
        }
    }

    /** @param  array<string, mixed>  $definition */
    private function movePresetDown(string $templateId, array $definition, string $signatureField): void
    {
        $presets = is_array($definition['presets'] ?? null) ? array_values($definition['presets']) : [];
        if (collect($presets)->contains(fn (mixed $preset): bool => is_array($preset) && ($preset['key'] ?? null) === self::FIELD)) {
            return;
        }
        foreach ($presets as $index => $preset) {
            if (! is_array($preset) || ($preset['field'] ?? null) !== $signatureField || ! is_array($preset['content'] ?? null)) {
                continue;
            }
            $split = $this->split($preset['content']);
            if ($split === null) {
                continue;
            }
            $presets[$index]['content'] = $split[0];
            array_splice($presets, $index, 0, [['key' => self::FIELD, 'label' => self::LABEL, 'field' => self::FIELD, 'quien_llena' => 'institucional', 'content' => $split[1]]]);
            $this->savePresets($templateId, $definition, $presets);

            return;
        }
    }

    /** @param  array<string, mixed>  $definition */
    private function movePresetBack(string $templateId, array $definition, string $signatureField): void
    {
        $presets = is_array($definition['presets'] ?? null) ? array_values($definition['presets']) : [];
        $libraryIndex = collect($presets)->search(fn (mixed $preset): bool => is_array($preset) && ($preset['key'] ?? null) === self::FIELD);
        if ($libraryIndex === false || ! is_array($presets[$libraryIndex]['content'] ?? null)) {
            return;
        }
        foreach ($presets as $index => $preset) {
            if (! is_array($preset) || ($preset['field'] ?? null) !== $signatureField || ! is_array($preset['content'] ?? null)) {
                continue;
            }
            $merged = $this->merge($preset['content'], $presets[$libraryIndex]['content']);
            if ($merged === null) {
                continue;
            }
            $presets[$index]['content'] = $merged;
            array_splice($presets, $libraryIndex, 1);
            $this->savePresets($templateId, $definition, $presets);

            return;
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<int, mixed>  $presets
     */
    private function savePresets(string $templateId, array $definition, array $presets): void
    {
        $definition['presets'] = array_values($presets);
        DB::table('plantilla_silabo')->where('plantilla_id', $templateId)
            ->update(['definicion_plantilla' => json_encode($definition, self::JSON)]);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{array<string, mixed>, array<string, mixed>}|null
     */
    private function split(array $document): ?array
    {
        foreach (is_array($document['content'] ?? null) ? $document['content'] : [] as $index => $node) {
            $rows = $this->rows($node);
            if ($rows === null || count($rows[0]['content']) < 2) {
                continue;
            }
            $column = null;
            foreach ($rows as $row) {
                foreach ($row['content'] as $position => $cell) {
                    if (array_intersect($this->variables($cell), self::LIBRARY_VARIABLES) !== []) {
                        $column ??= $position;
                    }
                }
            }
            if ($column === null) {
                continue;
            }
            $kept = [];
            $moved = [];
            foreach ($rows as $row) {
                $cells = $row['content'];
                $moved[] = [...$row, 'content' => [$cells[$column]]];
                array_splice($cells, $column, 1);
                $kept[] = [...$row, 'content' => $cells];
            }
            $document['content'][$index] = [...$node, 'content' => $kept];

            return [$document, ['type' => 'doc', 'content' => [[...$node, 'content' => $moved]]]];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $signatures
     * @param  array<string, mixed>  $library
     * @return array<string, mixed>|null
     */
    private function merge(array $signatures, array $library): ?array
    {
        $column = null;
        foreach (is_array($library['content'] ?? null) ? $library['content'] : [] as $node) {
            $rows = $this->rows($node);
            if ($rows !== null && count($rows[0]['content']) === 1) {
                $column = array_map(fn (array $row): mixed => $row['content'][0], $rows);
                break;
            }
        }
        if ($column === null) {
            return null;
        }
        foreach (is_array($signatures['content'] ?? null) ? $signatures['content'] : [] as $index => $node) {
            $rows = $this->rows($node);
            if ($rows === null || count($rows) !== count($column) || array_intersect($this->variables($node), self::LIBRARY_VARIABLES) !== []) {
                continue;
            }
            $signatures['content'][$index] = [...$node, 'content' => array_map(
                fn (array $row, mixed $cell): array => [...$row, 'content' => [...$row['content'], $cell]],
                $rows,
                $column,
            )];

            return $signatures;
        }

        return null;
    }

    /** @return list<array<string, mixed>>|null */
    private function rows(mixed $node): ?array
    {
        if (! is_array($node) || ($node['type'] ?? null) !== 'table' || ! is_array($node['content'] ?? null) || $node['content'] === []) {
            return null;
        }
        $width = null;
        foreach ($node['content'] as $row) {
            if (! is_array($row) || ! is_array($row['content'] ?? null) || ! array_is_list($row['content']) || $row['content'] === []) {
                return null;
            }
            foreach ($row['content'] as $cell) {
                if (! is_array($cell) || (int) ($cell['attrs']['colspan'] ?? 1) !== 1 || (int) ($cell['attrs']['rowspan'] ?? 1) !== 1) {
                    return null;
                }
            }
            $width ??= count($row['content']);
            if (count($row['content']) !== $width) {
                return null;
            }
        }

        return array_values($node['content']);
    }

    /** @return list<string> */
    private function variables(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }
        $found = ($node['type'] ?? null) === 'variable' && is_string($node['attrs']['id'] ?? null) ? [$node['attrs']['id']] : [];
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $found = [...$found, ...$this->variables($child)];
        }

        return $found;
    }

    /** @param  array<string, mixed>  $definition */
    private function librarySection(string $templateId, array $definition): ?string
    {
        $keys = array_values(array_filter([...(is_array($definition['library_sections'] ?? null) ? $definition['library_sections'] : []), 'bibliografia'], 'is_string'));
        foreach ($keys as $key) {
            $id = DB::table('secciones_plantilla')->where('plantilla_id', $templateId)->where('vigente_en_plantilla', true)
                ->where('clave_seccion_plantilla', $key)->value('seccion_plantilla_id');
            if ($id !== null) {
                return (string) $id;
            }
        }

        return null;
    }

    private function libraryField(string $templateId): ?stdClass
    {
        return DB::table('definiciones_campo')
            ->join('campos_plantilla', 'campos_plantilla.campo_plantilla_id', '=', 'definiciones_campo.campo_plantilla_id')
            ->where('campos_plantilla.plantilla_id', $templateId)
            ->where('campos_plantilla.vigente_en_plantilla', true)
            ->where('definiciones_campo.vigente_en_plantilla', true)
            ->where('campos_plantilla.clave_campo_plantilla', self::FIELD)
            ->first(['definiciones_campo.definicion_campo_id', 'definiciones_campo.campo_plantilla_id', 'definiciones_campo.contenido_definicion_campo']);
    }

    /** @return array<int, stdClass> */
    private function fields(string $templateId): array
    {
        return DB::table('definiciones_campo')
            ->join('campos_plantilla', 'campos_plantilla.campo_plantilla_id', '=', 'definiciones_campo.campo_plantilla_id')
            ->join('secciones_plantilla', 'secciones_plantilla.seccion_plantilla_id', '=', 'campos_plantilla.seccion_plantilla_id')
            ->where('campos_plantilla.plantilla_id', $templateId)
            ->where('campos_plantilla.vigente_en_plantilla', true)
            ->where('definiciones_campo.vigente_en_plantilla', true)
            ->where('secciones_plantilla.vigente_en_plantilla', true)
            ->orderByRaw("campos_plantilla.clave_campo_plantilla <> 'firmas'")
            ->orderBy('secciones_plantilla.posicion_seccion_plantilla')
            ->orderBy('campos_plantilla.posicion_campo_plantilla')
            ->get(['definiciones_campo.definicion_campo_id', 'definiciones_campo.contenido_definicion_campo', 'campos_plantilla.clave_campo_plantilla'])
            ->values()
            ->all();
    }

    private function retire(string $definitionId, string $blockId): void
    {
        $used = DB::table('valores_campo')->where('definicion_campo_id', $definitionId)->exists();
        if ($used) {
            DB::table('definiciones_campo')->where('definicion_campo_id', $definitionId)->update(['vigente_en_plantilla' => false]);
            DB::table('campos_plantilla')->where('campo_plantilla_id', $blockId)->update(['vigente_en_plantilla' => false]);

            return;
        }
        DB::table('definiciones_campo')->where('definicion_campo_id', $definitionId)->delete();
        DB::table('campos_plantilla')->where('campo_plantilla_id', $blockId)->delete();
    }

    /** @return array<string, mixed> */
    private function decode(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
};
