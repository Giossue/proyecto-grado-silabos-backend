<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La bibliografía deja de ser una tabla de texto y pasa a ser el bloque `bibliografia` de la
 * plantilla: cada columna tiene un nombre interno estable (autor, titulo, anio, ciudad,
 * editorial, isbn, codigo), así el buscador del catálogo de la biblioteca sabe dónde escribir
 * aunque Administración cambie los títulos de las columnas.
 *
 * Solo se cambian los campos que todavía tienen la tabla oficial de siete columnas; los que
 * Administración modificó se dejan como están. Lo que el docente ya escribió se conserva fila
 * por fila. Es una conversión sin cambio de diseño: no se marca la plantilla como modificada.
 */
return new class extends Migration
{
    private const FIELDS = ['bibliografia', 'bibliografia_complementaria'];

    private const HEADERS = ['Autor', 'Título', 'Año', 'Ciudad', 'Editorial', 'ISBN', 'Código'];

    private const COLUMNS = ['autor', 'titulo', 'anio', 'ciudad', 'editorial', 'isbn', 'codigo'];

    private const BLOCK = <<<'JSON'
{"label":"Bibliografía","props":[],"value":{"libros":{"type":"array","title":"libro","errorMessage":"La bibliografía admite de 1 a 40 libros.","minItems":1,"maxItems":40,"initial":1,"items":{"type":"object","properties":{"autor":{"type":"string","title":"Autor","errorMessage":"El dato «Autor» del libro supera los 1000 caracteres.","maxLength":1000,"multiline":false},"titulo":{"type":"string","title":"Título","errorMessage":"El dato «Título» del libro supera los 1000 caracteres.","maxLength":1000,"multiline":false},"anio":{"type":"string","title":"Año","errorMessage":"El dato «Año» del libro supera los 1000 caracteres.","maxLength":1000,"multiline":false},"ciudad":{"type":"string","title":"Ciudad","errorMessage":"El dato «Ciudad» del libro supera los 1000 caracteres.","maxLength":1000,"multiline":false},"editorial":{"type":"string","title":"Editorial","errorMessage":"El dato «Editorial» del libro supera los 1000 caracteres.","maxLength":1000,"multiline":false},"isbn":{"type":"string","title":"ISBN","errorMessage":"El dato «ISBN» del libro supera los 1000 caracteres.","maxLength":1000,"multiline":false},"codigo":{"type":"string","title":"Código","errorMessage":"El dato «Código» del libro supera los 1000 caracteres.","maxLength":1000,"multiline":false}}}}},"rules":[{"code":"libros_sin_titulo","on":"submit","message":"Escriba el título de cada libro: {detail}.","checks":[{"filled":"libros.*.titulo","equals":{"count":"libros"},"label":"Títulos"}],"hint":"Cada libro necesita su título para poder enviar"}],"layout":[{"type":"table","content":[{"type":"tableRow","content":[{"type":"tableHeader","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":"#C3D4EA","textColor":null,"textAlign":null,"bold":true,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"text","text":"Autor"}]}]},{"type":"tableHeader","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":"#C3D4EA","textColor":null,"textAlign":null,"bold":true,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"text","text":"Título"}]}]},{"type":"tableHeader","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":"#C3D4EA","textColor":null,"textAlign":null,"bold":true,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"text","text":"Año"}]}]},{"type":"tableHeader","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":"#C3D4EA","textColor":null,"textAlign":null,"bold":true,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"text","text":"Ciudad"}]}]},{"type":"tableHeader","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":"#C3D4EA","textColor":null,"textAlign":null,"bold":true,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"text","text":"Editorial"}]}]},{"type":"tableHeader","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":"#C3D4EA","textColor":null,"textAlign":null,"bold":true,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"text","text":"ISBN"}]}]},{"type":"tableHeader","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":"#C3D4EA","textColor":null,"textAlign":null,"bold":true,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"text","text":"Código"}]}]}]},{"type":"tableRow","attrs":{"repeat":{"value":"libros"}},"content":[{"type":"tableCell","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":null,"textColor":null,"textAlign":null,"bold":null,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"slot","attrs":{"value":"autor"}}]}]},{"type":"tableCell","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":null,"textColor":null,"textAlign":null,"bold":null,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"slot","attrs":{"value":"titulo"}}]}]},{"type":"tableCell","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":null,"textColor":null,"textAlign":null,"bold":null,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"slot","attrs":{"value":"anio"}}]}]},{"type":"tableCell","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":null,"textColor":null,"textAlign":null,"bold":null,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"slot","attrs":{"value":"ciudad"}}]}]},{"type":"tableCell","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":null,"textColor":null,"textAlign":null,"bold":null,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"slot","attrs":{"value":"editorial"}}]}]},{"type":"tableCell","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":null,"textColor":null,"textAlign":null,"bold":null,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"slot","attrs":{"value":"isbn"}}]}]},{"type":"tableCell","attrs":{"colspan":1,"rowspan":1,"colwidth":null,"backgroundColor":null,"textColor":null,"textAlign":null,"bold":null,"italic":null,"borderStyle":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"slot","attrs":{"value":"codigo"}}]}]}]}]}],"catalog":{"list":"libros","map":{"author":"autor","title":"titulo","year":"anio","place":"ciudad","publisher":"editorial","isbn":"isbn"}}}
JSON;

    public function up(): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla', 'plantilla_modificada_en']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            if (! is_array($definition) || ! is_array($definition['blocks'] ?? null)) {
                continue;
            }
            $converted = false;
            $fields = DB::table('definiciones_campo')
                ->join('campos_plantilla', 'campos_plantilla.campo_plantilla_id', '=', 'definiciones_campo.campo_plantilla_id')
                ->where('definiciones_campo.plantilla_id', $template->plantilla_id)
                ->where('definiciones_campo.vigente_en_plantilla', true)
                ->whereIn('campos_plantilla.clave_campo_plantilla', self::FIELDS)
                ->get(['definiciones_campo.definicion_campo_id', 'definiciones_campo.contenido_definicion_campo']);
            foreach ($fields as $field) {
                if (! $this->isOfficialTable(json_decode((string) $field->contenido_definicion_campo, true))) {
                    continue;
                }
                DB::table('definiciones_campo')->where('definicion_campo_id', $field->definicion_campo_id)
                    ->update(['contenido_definicion_campo' => $this->encode($this->document([]))]);
                foreach (DB::table('valores_campo')->where('definicion_campo_id', $field->definicion_campo_id)->get(['valor_campo_id', 'valor_campo']) as $value) {
                    DB::table('valores_campo')->where('valor_campo_id', $value->valor_campo_id)
                        ->update(['valor_campo' => $this->encode($this->document($this->books(json_decode((string) $value->valor_campo, true))))]);
                }
                $converted = true;
            }
            if ($converted && ! array_key_exists('bibliografia', $definition['blocks'])) {
                $definition['blocks']['bibliografia'] = json_decode(self::BLOCK, true, 512, JSON_THROW_ON_ERROR);
                DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                    ->update(['definicion_plantilla' => $this->encode($definition)]);
            }
            // Conversión sin cambio de diseño: no debe pedir rehacer sílabos.
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['plantilla_modificada_en' => $template->plantilla_modificada_en]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: la tabla de texto no distingue las columnas por nombre.
    }

    /**
     * @param  list<array<string, string>>  $books
     * @return array<string, mixed>
     */
    private function document(array $books): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'documentBlock', 'attrs' => [
            'kind' => 'bibliografia',
            'props' => (object) [],
            'value' => ['libros' => $books === [] ? [array_fill_keys(self::COLUMNS, '')] : $books],
        ]]]];
    }

    private function isOfficialTable(mixed $content): bool
    {
        $table = $this->table($content);
        $rows = is_array($table) && is_array($table['content'] ?? null) ? $table['content'] : [];

        return $rows !== [] && count($content['content']) === 1 && $this->cells($rows[0]) === self::HEADERS;
    }

    /** @return list<array<string, string>> */
    private function books(mixed $value): array
    {
        $table = $this->table($value);
        $books = [];
        foreach (array_slice(is_array($table) && is_array($table['content'] ?? null) ? $table['content'] : [], 1) as $row) {
            $cells = $this->cells($row);
            if (count($cells) === count(self::COLUMNS) && implode('', $cells) !== '') {
                $books[] = array_combine(self::COLUMNS, $cells);
            }
        }

        return $books;
    }

    /** @return array<string, mixed>|null */
    private function table(mixed $document): ?array
    {
        foreach (is_array($document) && is_array($document['content'] ?? null) ? $document['content'] : [] as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'table') {
                return $node;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function cells(mixed $row): array
    {
        $cells = [];
        foreach (is_array($row) && is_array($row['content'] ?? null) ? $row['content'] : [] as $cell) {
            $cells[] = trim((string) preg_replace('/\s+/u', ' ', $this->text($cell)));
        }

        return $cells;
    }

    private function text(mixed $node): string
    {
        if (! is_array($node)) {
            return '';
        }
        $text = is_string($node['text'] ?? null) ? $node['text'] : '';
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $text .= ' '.$this->text($child);
        }

        return $text;
    }

    private function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
