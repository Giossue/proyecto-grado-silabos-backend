<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    private const REMOVED_VARIABLES = [
        'resultados_aprendizaje_asignatura',
        'fuente_resultados_aprendizaje',
        'ubicacion_resultados_aprendizaje',
    ];

    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS valores_curriculares');
        DB::statement('DROP TABLE IF EXISTS conceptos_curriculares');
        DB::statement('DROP TABLE IF EXISTS importaciones_curriculares');
        DB::statement('DROP TABLE IF EXISTS documentos_importacion');
        DB::statement('DROP FUNCTION IF EXISTS proteger_forma_concepto_curricular()');
        DB::statement('DROP FUNCTION IF EXISTS validar_tipo_valor_curricular()');

        foreach (DB::table('campos_plantilla')->select(['id', 'configuracion_campo_plantilla'])->get() as $row) {
            $configuration = json_decode((string) $row->configuracion_campo_plantilla, true);
            if (! is_array($configuration)) {
                continue;
            }
            $cleaned = $configuration;
            unset($cleaned['options_sources']);
            if (is_array($cleaned['document'] ?? null)) {
                $cleaned['document'] = $this->withoutProjectData($cleaned['document']);
            }
            if ($cleaned !== $configuration) {
                DB::table('campos_plantilla')->where('id', $row->id)->update([
                    'configuracion_campo_plantilla' => json_encode($cleaned, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        DB::statement("UPDATE silabos SET datos_academicos_silabo = datos_academicos_silabo - 'curricular' WHERE jsonb_exists(datos_academicos_silabo, 'curricular')");
        $files = DB::table('archivos')->where('categoria', 'like', 'curricular\_%')
            ->orWhere('categoria', 'like', 'academic\_source\_%')
            ->get(['id', 'disco', 'ruta_archivo']);
        foreach ($files as $file) {
            Storage::disk((string) $file->disco)->delete((string) $file->ruta_archivo);
        }
        DB::table('archivos')->whereIn('id', $files->pluck('id')->all())->delete();
        DB::statement('RESET app.reinicio_institucional');
        Storage::disk('private')->deleteDirectory('academic-sources');
    }

    public function down(): void
    {
        throw new LogicException('El Proyecto de carrera se retiró definitivamente; restaure un respaldo para recuperarlo.');
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function withoutProjectData(array $node): array
    {
        if (($node['type'] ?? null) === 'field' && is_array($node['attrs'] ?? null)) {
            unset($node['attrs']['options_source'], $node['attrs']['options_sources']);
        }
        if (is_array($node['content'] ?? null)) {
            $content = [];
            foreach ($node['content'] as $child) {
                if (is_array($child) && ($child['type'] ?? null) === 'variable') {
                    $id = (string) ($child['attrs']['id'] ?? '');
                    if (str_starts_with($id, 'curricular_') || in_array($id, self::REMOVED_VARIABLES, true)) {
                        continue;
                    }
                }
                $content[] = is_array($child) ? $this->withoutProjectData($child) : $child;
            }
            $node['content'] = $content;
        }

        return $node;
    }
};
