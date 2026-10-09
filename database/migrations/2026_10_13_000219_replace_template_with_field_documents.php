<?php

use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Infrastructure\Rendering\HistoricalFieldDocument;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Application\IdentificationCard;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $originalModifiedAt = DB::table('plantilla_silabo')->value('plantilla_modificada_en');
        Schema::table('definiciones_campo', function (Blueprint $table): void {
            $table->string('quien_llena_definicion_campo', 20)->default('docente');
            $table->jsonb('contenido_definicion_campo')->default('{"type":"doc","content":[{"type":"paragraph"}]}');
        });
        Schema::table('secciones_plantilla', function (Blueprint $table): void {
            $table->string('orientacion_seccion_plantilla', 12)->default('portrait');
        });
        DB::statement("UPDATE definiciones_campo SET quien_llena_definicion_campo = CASE WHEN origen_dato_definicion_campo = 'ficha' THEN 'coordinacion' WHEN tipo_definicion_campo IN ('automatico', 'fijo') THEN 'institucional' ELSE 'docente' END");
        $definitions = DB::table('definiciones_campo')->join('campos_plantilla', 'campos_plantilla.campo_plantilla_id', '=', 'definiciones_campo.campo_plantilla_id')
            ->where('definiciones_campo.vigente_en_plantilla', true)->get(['definiciones_campo.*', 'configuracion_campo_plantilla']);
        $responsibles = [];
        foreach (config('syllabus_template.designs') as $design) {
            foreach ($design['fields'] as $field) {
                $responsibles[$field[0]] = $field[2];
            }
        }
        foreach ($definitions as $definition) {
            $config = json_decode($definition->opciones_definicion_campo ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $block = json_decode($definition->configuracion_campo_plantilla ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $document = HistoricalFieldDocument::of([
                'type' => $definition->tipo_definicion_campo, 'config' => $config,
                'value' => $block['initial_content'] ?? null,
            ]);
            DB::table('definiciones_campo')->where('definicion_campo_id', $definition->definicion_campo_id)->update([
                'quien_llena_definicion_campo' => $responsibles[$definition->clave_definicion_campo] ?? $definition->quien_llena_definicion_campo,
                'contenido_definicion_campo' => json_encode(FieldDocument::normalize($document), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            if (($config['page_orientation'] ?? $block['page_orientation'] ?? null) === 'landscape') {
                DB::table('secciones_plantilla')->where('seccion_plantilla_id', DB::table('campos_plantilla')->where('campo_plantilla_id', $definition->campo_plantilla_id)->value('seccion_plantilla_id'))
                    ->update(['orientacion_seccion_plantilla' => 'landscape']);
            }
        }
        $archivedDrafts = Syllabus::query()->whereIn('estado', ['sin_iniciar', 'borrador', 'correccion_solicitada'])
            ->where(fn ($query) => $query->whereHas('revisions.approval')->orWhereHas('convocation', fn ($convocation) => $convocation
                ->where('estado', Convocation::STATE_CLOSED)
                ->orWhereHas('process', fn ($process) => $process->where('estado', 'cerrado'))))
            ->with('values')->lockForUpdate()->get();
        foreach ($archivedDrafts as $syllabus) {
            foreach ($definitions as $definition) {
                $config = json_decode($definition->opciones_definicion_campo ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                $stored = $syllabus->values->firstWhere('definicion_campo_id', $definition->definicion_campo_id);
                $document = HistoricalFieldDocument::of([
                    'type' => $definition->tipo_definicion_campo, 'config' => $config, 'value' => $stored?->valor,
                ]);
                if ($definition->tipo_definicion_campo === 'automatico' && ($config['source'] ?? null) === 'identification_card') {
                    $document = IdentificationCard::document(IdentificationCard::fromSyllabus($syllabus));
                }
                FieldValue::query()->updateOrCreate([
                    'silabo_id' => $syllabus->id, 'definicion_campo_id' => $definition->definicion_campo_id,
                ], ['valor' => FieldDocument::normalize($document)]);
            }
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS validar_condicion_definicion ON definiciones_campo;
            DROP TRIGGER IF EXISTS propagar_clave_condicion ON definiciones_campo;
            DROP FUNCTION IF EXISTS validar_condicion_definicion();
            DROP FUNCTION IF EXISTS propagar_clave_condicion();
            ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_tipo_check;
            ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_heredado_check;
            ALTER TABLE definiciones_campo
                DROP COLUMN tipo_definicion_campo,
                DROP COLUMN clase_anterior_definicion_campo,
                DROP COLUMN heredado_definicion_campo,
                DROP COLUMN origen_dato_definicion_campo,
                DROP COLUMN editable_docente_definicion_campo,
                DROP COLUMN opciones_definicion_campo,
                DROP COLUMN condicion_definicion_campo;
            ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_responsable_check
                CHECK (quien_llena_definicion_campo IN ('docente', 'coordinacion', 'institucional'));
            ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_documento_check
                CHECK (contenido_definicion_campo ? 'type' AND contenido_definicion_campo ? 'content' AND jsonb_typeof(contenido_definicion_campo) = 'object' AND contenido_definicion_campo->>'type' = 'doc' AND jsonb_typeof(contenido_definicion_campo->'content') = 'array');
            ALTER TABLE secciones_plantilla ADD CONSTRAINT secciones_plantilla_orientacion_check
                CHECK (orientacion_seccion_plantilla IN ('portrait', 'landscape'));
            SQL);

        $template = SyllabusTemplate::query()->lockForUpdate()->first();
        if ($template === null) {
            return;
        }
        $modifiedAt = $originalModifiedAt;
        $profiles = DB::table('valores_campo')->join('definiciones_campo', 'definiciones_campo.definicion_campo_id', '=', 'valores_campo.definicion_campo_id')
            ->whereNotNull('asignatura_id')->where('vigente_en_plantilla', true)
            ->get(['asignatura_id', 'clave_definicion_campo', 'valor_campo']);
        $syllabi = Syllabus::query()->whereDoesntHave('revisions.approval')
            ->where('estado', '!=', 'aprobado')
            ->whereHas('convocation', fn ($query) => $query->whereIn('estado', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED])
                ->whereHas('process', fn ($process) => $process->whereIn('estado', ['abierto', 'pausado'])))
            ->with(['convocation.process', 'parallel'])->lockForUpdate()->get();
        app(InProgressWork::class)->purge($syllabi->pluck('id')->all());

        $template->update(['documento' => ['version_esquema' => 1, 'secciones' => []]]);
        $template = app(SyllabusTemplateProvisioner::class)->format($template->fresh());
        $fields = $template->fields()->where('quien_llena', FieldDefinition::COORDINATION)->get()->keyBy('clave');
        foreach ($profiles as $profile) {
            $field = $fields->get($profile->clave_definicion_campo);
            $value = is_string($profile->valor_campo) ? json_decode($profile->valor_campo, true, 512, JSON_THROW_ON_ERROR) : $profile->valor_campo;
            if ($field === null || ! FreeText::isDocument($value)) {
                continue;
            }
            DB::table('valores_campo')->updateOrInsert(['asignatura_id' => $profile->asignatura_id, 'definicion_campo_id' => $field->id], [
                'valor_campo_id' => (string) Str::uuid(),
                'silabo_id' => null,
                'valor_campo' => json_encode(FieldDocument::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        }
        foreach ($syllabi as $syllabus) {
            app(GenerateConvocationSyllabi::class)->generateForParallel($syllabus->convocation, $syllabus->parallel);
        }
        DB::table('plantilla_silabo')->where('plantilla_id', $template->id)->update(['plantilla_modificada_en' => $modifiedAt]);
    }

    public function down(): void
    {
        throw new RuntimeException('I-210 no admite reversión automática; restaure el respaldo previo.');
    }
};
