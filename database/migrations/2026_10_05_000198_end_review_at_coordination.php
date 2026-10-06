<?php

use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('asignaciones_rol')->where('rol', 'decano')->exists()
            || DB::table('firmas_documento')->where('firmado_como', 'decanato')->exists()
            || DB::table('revisiones_silabo')->where('correccion_etapa', 'decanato')->exists()
            || DB::table('aprobaciones')
                ->join('revisiones_coordinacion', 'revisiones_coordinacion.revision_silabo_id', '=', 'aprobaciones.revision_silabo_id')
                ->whereColumn('aprobaciones.aprobado_por', '!=', 'revisiones_coordinacion.revisado_por')
                ->exists()) {
            throw new RuntimeException('Existen datos históricos de decanato. Resuelva su conservación antes de retirar este rol.');
        }

        $blocks = DB::table('campos_plantilla')
            ->where('vigente_en_plantilla', true)
            ->whereRaw('configuracion_campo_plantilla::text ILIKE ?', ['%decan%'])
            ->get();
        if ($blocks->isNotEmpty() && DB::table('silabos')->whereIn('estado_silabo', ['borrador', 'en_revision', 'correccion_solicitada'])->exists()) {
            throw new RuntimeException('La plantilla aún contiene la firma del decano y hay sílabos en curso. Aplique la corrección institucional antes de migrar.');
        }
        foreach ($blocks as $block) {
            if ($block->clave_campo_plantilla !== 'revision_principal') {
                throw new RuntimeException('Una parte personalizada de la plantilla aún menciona al decano. Corríjala antes de migrar.');
            }
            $configuration = json_decode((string) $block->configuracion_campo_plantilla, true, 512, JSON_THROW_ON_ERROR);
            $rows = $configuration['document']['content'][0]['content'] ?? null;
            if (! is_array($rows) || count($rows) !== 5) {
                throw new RuntimeException('La tabla de firmas necesita revisión manual antes de retirar al decano.');
            }
            foreach ($rows as $index => $row) {
                if (! is_array($row['content'] ?? null) || count($row['content']) !== 4) {
                    throw new RuntimeException('La tabla de firmas necesita revisión manual antes de retirar al decano.');
                }
                array_pop($rows[$index]['content']);
            }
            $defaults = TemplateDocumentDefaults::signatures()['content'][0]['content'];
            $rows[0]['content'][1] = $defaults[0]['content'][1];
            $rows[4]['content'][1] = $defaults[4]['content'][1];
            $configuration['document']['content'][0]['content'] = $rows;
            if (stripos(json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'decan') !== false) {
                throw new RuntimeException('La tabla de firmas conserva una referencia al decano. Corríjala antes de migrar.');
            }
            DB::table('campos_plantilla')->where('campo_plantilla_id', $block->campo_plantilla_id)->update([
                'configuracion_campo_plantilla' => json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        }

        DB::statement('DROP INDEX asignaciones_rol_decano_facultad_unica');
        DB::statement('ALTER TABLE asignaciones_rol DROP CONSTRAINT asignaciones_rol_rol_valido_check');
        DB::statement('ALTER TABLE asignaciones_rol DROP CONSTRAINT asignaciones_rol_alcance_valido_check');
        DB::statement(<<<'SQL'
            ALTER TABLE asignaciones_rol
            ADD CONSTRAINT asignaciones_rol_rol_valido_check
            CHECK (rol IN ('administrador', 'coordinador', 'docente', 'bibliotecario')),
            ADD CONSTRAINT asignaciones_rol_alcance_valido_check
            CHECK (
                (rol IN ('administrador', 'bibliotecario') AND carrera_id IS NULL)
                OR (rol IN ('coordinador', 'docente') AND carrera_id IS NOT NULL)
            )
        SQL);
        DB::statement('DROP INDEX asignaciones_rol_facultad_id_index');
        DB::statement('ALTER TABLE asignaciones_rol DROP COLUMN facultad_id');

        DB::statement('ALTER TABLE firmas_documento DROP CONSTRAINT firmas_documento_firmado_como_check');
        DB::statement(<<<'SQL'
            ALTER TABLE firmas_documento
            ADD CONSTRAINT firmas_documento_firmado_como_check
            CHECK (firmado_como IN ('docente', 'coordinacion', 'biblioteca'))
        SQL);
        DB::statement('ALTER TABLE revisiones_silabo DROP CONSTRAINT revisiones_silabo_correccion_etapa_check');
        DB::statement(<<<'SQL'
            ALTER TABLE revisiones_silabo
            ADD CONSTRAINT revisiones_silabo_correccion_etapa_check
            CHECK (correccion_etapa IN ('biblioteca', 'coordinacion'))
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('La migración 000198 no se revierte: restaure el respaldo previo.');
    }
};
