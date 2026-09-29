<?php

use App\Modules\Configuration\Domain\FreeText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * I-156: las partes que llena el docente pasan a Texto libre y se retiran las filas
 * repetibles. Lo ya escrito se conserva: las filas de una sola columna se vuelven una
 * lista y las de varias, una tabla con encabezado; el texto largo, párrafos. Las
 * fotografías de revisiones enviadas no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('plantilla_silabo')->get(['id', 'documento_plantilla']) as $template) {
                $document = json_decode((string) $template->documento_plantilla, true, 512, JSON_THROW_ON_ERROR);
                foreach ($document['secciones'] ?? [] as $sectionIndex => $section) {
                    foreach ($section['bloques'] ?? [] as $blockIndex => $block) {
                        $converted = $this->convertBlock($block, (string) ($section['titulo'] ?? ''));
                        if ($converted !== null) {
                            $document['secciones'][$sectionIndex]['bloques'][$blockIndex] = $converted;
                        }
                    }
                }
                DB::table('plantilla_silabo')->where('id', $template->id)->update([
                    'documento_plantilla' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            }
            // Ninguna pregunta queda como filas repetibles.
            DB::table('definiciones_campo')->where('tipo_definicion_campo', 'repetible')->update(['tipo_definicion_campo' => 'texto_libre']);
        });

        Schema::dropIfExists('filas_repetibles');
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_tipo_check');
        DB::statement(<<<'SQL'
            ALTER TABLE definiciones_campo
            ADD CONSTRAINT definiciones_campo_tipo_check
            CHECK (tipo_definicion_campo IN (
                'texto_corto', 'texto_largo', 'texto_libre', 'numero', 'fecha',
                'seleccion_unica', 'seleccion_multiple', 'booleano', 'calculo', 'referencia_maestra'
            ))
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('La conversión a Texto libre (I-156) no se revierte: restaure el respaldo previo.');
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function convertBlock(array $block, string $sectionTitle): ?array
    {
        $configuration = is_array($block['configuracion'] ?? null) ? $block['configuracion'] : [];
        $type = $configuration['content_type'] ?? null;
        $design = is_array($configuration['document'] ?? null) ? $configuration['document'] : null;
        $repeated = $design !== null && $this->hasRepeatedRows($design);
        if (! $repeated && ! in_array($type, ['bulleted_list', 'numbered_list', 'table'], true) && ! ($type === 'text' && $design === null)) {
            return null;
        }
        $field = DB::table('definiciones_campo')->where('bloque_plantilla_id', $block['id'])->where('heredado', false)
            ->orderBy('posicion_definicion_campo')->first();
        if ($field === null) {
            return null;
        }
        $label = trim((string) ($block['titulo'] ?? '')) !== '' ? trim((string) $block['titulo']) : $sectionTitle;
        $layout = is_array($configuration['table'] ?? null) ? $configuration['table'] : [];
        $columns = [];
        foreach ([...($layout['header_fields'] ?? []), ...($layout['columns'] ?? [])] as $column) {
            if (is_array($column) && is_string($column['key'] ?? null)) {
                $columns[] = ['key' => $column['key'], 'label' => (string) ($column['label'] ?? $column['key'])];
            }
        }
        $columns = $columns === [] ? [['key' => 'texto', 'label' => $label]] : $columns;
        $this->convertValues((string) $field->id, $columns, $type === 'numbered_list');
        DB::table('definiciones_campo')->where('id', $field->id)->update(['tipo_definicion_campo' => 'texto_libre']);

        unset($configuration['table'], $configuration['options_sources'], $configuration['detached_fields']);
        $configuration['content_type'] = 'text';
        $configuration['document'] = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [[
            'type' => 'field', 'attrs' => ['key' => $field->clave_definicion_campo, 'label' => $label, 'kind' => 'texto_libre'],
        ]]]]];

        return [...$block, 'configuracion' => $configuration];
    }

    /** @param list<array{key: string, label: string}> $columns */
    private function convertValues(string $fieldId, array $columns, bool $numbered): void
    {
        if (Schema::hasTable('filas_repetibles')) {
            $rows = DB::table('filas_repetibles')->where('definicion_campo_id', $fieldId)
                ->orderBy('silabo_id')->orderBy('posicion_fila_repetible')->get(['silabo_id', 'datos_fila_repetible'])
                ->groupBy('silabo_id');
            foreach ($rows as $syllabusId => $syllabusRows) {
                $data = $syllabusRows->map(fn (object $row): array => (array) json_decode((string) $row->datos_fila_repetible, true))->values()->all();
                $this->store((string) $syllabusId, $fieldId, FreeText::fromRows($columns, $data, $numbered));
            }
        }
        foreach (DB::table('valores_campo')->where('definicion_campo_id', $fieldId)->get(['silabo_id', 'valor_campo']) as $value) {
            $current = json_decode((string) $value->valor_campo, true);
            if (is_string($current)) {
                $this->store((string) $value->silabo_id, $fieldId, FreeText::fromText($current));
            }
        }
    }

    /** @param array<string, mixed> $document */
    private function store(string $syllabusId, string $fieldId, array $document): void
    {
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $updated = DB::table('valores_campo')->where('silabo_id', $syllabusId)->where('definicion_campo_id', $fieldId)
            ->update(['valor_campo' => $json]);
        if ($updated === 0) {
            DB::table('valores_campo')->insert([
                'id' => (string) Str::uuid7(), 'silabo_id' => $syllabusId, 'definicion_campo_id' => $fieldId,
                'valor_campo' => $json, 'heredado' => false, 'origen_valor_campo' => null,
            ]);
        }
    }

    /** @param array<string, mixed> $node */
    private function hasRepeatedRows(array $node): bool
    {
        if (($node['type'] ?? null) === 'column' || (($node['type'] ?? null) === 'table' && ($node['attrs']['repeatKey'] ?? null) !== null)) {
            return true;
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child) && $this->hasRepeatedRows($child)) {
                return true;
            }
        }

        return false;
    }
};
