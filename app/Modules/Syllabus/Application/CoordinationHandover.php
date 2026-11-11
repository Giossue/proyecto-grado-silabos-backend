<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Cuando cambia el coordinador de una carrera con una convocatoria en curso, ningún sílabo de esa
 * convocatoria puede quedar con la firma del anterior: lo que él aprobó o firmó pierde esa aprobación y
 * esa firma, y espera la del nuevo. La verificación de Biblioteca, que va antes, se conserva.
 * El contenido del docente y su firma no cambian.
 */
class CoordinationHandover
{
    /** @return list<string> Sílabos que pierden la aprobación o la firma de coordinación. */
    public function returnApprovals(string $careerId): array
    {
        $syllabi = Syllabus::query()
            ->whereIn('estado', ['en_revision', 'aprobado'])
            ->whereHas('convocation', fn ($query) => $query->where('carrera_id', $careerId)
                ->where('estado', '!=', Convocation::STATE_CLOSED))
            ->lockForUpdate()
            ->get();
        $returned = [];
        foreach ($this->decided($syllabi) as [$syllabus, $revision]) {
            $this->undoDecisions((string) $syllabus->id, (string) $revision->id);
            $syllabus->update(['estado' => 'en_revision', 'version_bloqueo' => $syllabus->version_bloqueo + 1]);
            $returned[] = (string) $syllabus->id;
        }

        return $returned;
    }

    /**
     * @param  iterable<Syllabus>  $syllabi
     * @return list<array{Syllabus, SyllabusRevision}>
     */
    public function decided(iterable $syllabi): array
    {
        $decided = [];
        foreach ($syllabi as $syllabus) {
            if (! in_array($syllabus->estado, ['en_revision', 'aprobado'], true)) {
                continue;
            }
            $revision = SyllabusRevision::query()->where('silabo_id', $syllabus->id)->orderByDesc('numero_revision')->first();
            if ($revision !== null && $this->coordinationActed($syllabus, $revision)) {
                $decided[] = [$syllabus, $revision];
            }
        }

        return $decided;
    }

    private function coordinationActed(Syllabus $syllabus, SyllabusRevision $revision): bool
    {
        return $syllabus->estado === 'aprobado'
            || DB::table('aprobaciones')->where('revision_silabo_id', $revision->id)->exists()
            || DB::table('revisiones_coordinacion')->where('revision_silabo_id', $revision->id)->exists()
            || DB::table('firmas_silabo')->where('silabo_id', $syllabus->id)->where('firmado_como', SyllabusSignature::ROLE_COORDINATION)->exists();
    }

    private function undoDecisions(string $syllabusId, string $revisionId): void
    {
        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        $exports = DB::table('exportaciones_silabo')->where('revision_silabo_id', $revisionId)->get(['archivo_pdf_id']);
        $objectIds = $exports->pluck('archivo_pdf_id')->filter()->unique()->values()->all();
        $objects = DB::table('archivos')->whereIn('archivo_id', $objectIds)->get(['disco', 'ruta_archivo']);
        DB::table('firmas_silabo')->where('silabo_id', $syllabusId)->where('firmado_como', SyllabusSignature::ROLE_COORDINATION)->delete();
        DB::table('exportaciones_silabo')->where('revision_silabo_id', $revisionId)->delete();
        DB::table('archivos')->whereIn('archivo_id', $objectIds)->delete();
        DB::table('aprobaciones')->where('revision_silabo_id', $revisionId)->delete();
        DB::table('revisiones_coordinacion')->where('revision_silabo_id', $revisionId)->delete();
        DB::statement("SET LOCAL app.reinicio_institucional = 'off'");
        DB::afterCommit(function () use ($objects): void {
            foreach ($objects as $object) {
                Storage::disk($object->disco)->delete($object->ruta_archivo);
            }
        });
    }
}
