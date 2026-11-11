<?php

namespace App\Modules\Library\Application;

use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\Search\AccentInsensitive;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class LibraryReviews
{
    public const STATE_PENDING = 'pendiente';

    public const STATE_HANDLED = 'atendido';

    public const STATES = [self::STATE_PENDING, self::STATE_HANDLED];

    public const OUTCOME_SIGNED = 'firmado';

    public const OUTCOME_RETURNED = 'devuelto';

    public const OUTCOME_REPLACED = 'reemplazado';

    public const OUTCOMES = [self::OUTCOME_SIGNED, self::OUTCOME_RETURNED, self::OUTCOME_REPLACED];

    public const PERIOD_ANY = 'cualquiera';

    public const PERIOD_CURRENT = 'actual';

    public const PERIOD_PAST = 'terminado';

    private const OUTCOME_SQL = <<<'SQL'
        CASE
            WHEN envios_biblioteca.verificado_en IS NOT NULL THEN 'firmado'
            WHEN revision.correccion_etapa = 'biblioteca' THEN 'devuelto'
            WHEN EXISTS (
                SELECT 1 FROM revisiones_silabo posterior
                WHERE posterior.silabo_id = revision.silabo_id AND posterior.numero_revision > revision.numero_revision
            ) THEN 'reemplazado'
            ELSE 'pendiente'
        END
        SQL;

    // Quien atiende el sílabo: quien lo verificó, quien lo devolvió o, en un reenvío, quien lo verificó o devolvió antes.
    private const RESPONSIBLE_SQL = <<<'SQL'
        COALESCE(
            envios_biblioteca.verificado_por,
            CASE WHEN revision.correccion_etapa = 'biblioteca' THEN revision.correccion_solicitada_por END,
            (
                SELECT COALESCE(envio_anterior.verificado_por, anterior.correccion_solicitada_por) FROM revisiones_silabo anterior
                LEFT JOIN envios_biblioteca envio_anterior ON envio_anterior.revision_silabo_id = anterior.revision_silabo_id
                WHERE anterior.silabo_id = revision.silabo_id AND anterior.numero_revision < revision.numero_revision
                  AND (envio_anterior.verificado_por IS NOT NULL OR anterior.correccion_etapa = 'biblioteca')
                ORDER BY anterior.numero_revision DESC LIMIT 1
            )
        )
        SQL;

    private const STATE_SQL = 'CASE ('.self::OUTCOME_SQL.") WHEN 'pendiente' THEN 'pendiente' ELSE 'atendido' END";

    /** @return Builder<LibrarySubmission> */
    public function query(
        ?string $state = null,
        ?string $search = null,
        ?string $facultyId = null,
        string $period = self::PERIOD_ANY,
        ?string $periodId = null,
        ?string $handledBy = null,
        bool $latestOnly = false,
        bool $withCarried = false,
    ): Builder {
        return LibrarySubmission::query()
            ->join('revisiones_silabo as revision', 'revision.revision_silabo_id', '=', 'envios_biblioteca.revision_silabo_id')
            ->join('silabos as silabo', 'silabo.silabo_id', '=', 'revision.silabo_id')
            ->select('envios_biblioteca.*', 'revision.revision_silabo_id as revision_id', 'revision.silabo_id as silabo_id', 'revision.numero_revision as numero_revision')
            ->selectRaw(self::STATE_SQL.' AS estado_envio')
            ->selectRaw(self::OUTCOME_SQL.' AS resultado_envio')
            ->selectRaw(self::RESPONSIBLE_SQL.' AS responsable_id')
            ->when(! $withCarried, fn (Builder $query) => $query->where('envios_biblioteca.verificacion_heredada', false))
            // Un sílabo aparece una sola vez: con su envío más reciente que pasó por biblioteca.
            ->when($latestOnly, fn (Builder $query) => $query->whereNotExists(
                fn ($later) => $later->selectRaw('1')->from('revisiones_silabo as posterior')
                    ->join('envios_biblioteca as envio_posterior', 'envio_posterior.revision_silabo_id', '=', 'posterior.revision_silabo_id')
                    ->where('envio_posterior.verificacion_heredada', false)
                    ->whereColumn('posterior.silabo_id', 'revision.silabo_id')
                    ->whereColumn('posterior.numero_revision', '>', 'revision.numero_revision'),
            ))
            ->when($state !== null, fn (Builder $query) => in_array($state, self::OUTCOMES, true)
                ? $query->whereRaw('('.self::OUTCOME_SQL.') = ?', [$state])
                : $query->whereRaw('('.self::STATE_SQL.') = ?', [$state]))
            ->when($search !== null && $search !== '', fn (Builder $query) => $query->where(
                fn (Builder $outer) => $outer
                    ->whereRaw(AccentInsensitive::matches("silabo.datos_academicos_silabo->'subject'->>'name'"), [AccentInsensitive::pattern($search)])
                    ->orWhereRaw(AccentInsensitive::matches("silabo.datos_academicos_silabo->'subject'->>'code'"), [AccentInsensitive::pattern($search)])
                    ->orWhereExists(fn ($career) => $career->selectRaw('1')
                        ->from('convocatorias_carreras as convocatoria_busqueda')
                        ->join('carreras as carrera_busqueda', 'carrera_busqueda.carrera_id', '=', 'convocatoria_busqueda.carrera_id')
                        ->whereColumn('convocatoria_busqueda.convocatoria_carrera_id', 'silabo.convocatoria_carrera_id')
                        ->whereRaw(AccentInsensitive::matches('carrera_busqueda.nombre_carrera'), [AccentInsensitive::pattern($search)]))
                    ->orWhereExists(fn ($teacher) => $teacher->selectRaw('1')
                        ->from('docentes_vigentes_silabo as docente_busqueda')
                        ->join('usuarios as usuario_busqueda', 'usuario_busqueda.usuario_id', '=', 'docente_busqueda.usuario_id')
                        ->whereColumn('docente_busqueda.silabo_id', 'silabo.silabo_id')
                        ->whereRaw(AccentInsensitive::matches('usuario_busqueda.nombre_usuario'), [AccentInsensitive::pattern($search)])),
            ))
            ->when($facultyId !== null, fn (Builder $query) => $query->whereExists(
                fn ($career) => $career->selectRaw('1')
                    ->from('convocatorias_carreras as convocatoria')
                    ->join('carreras as carrera', 'carrera.carrera_id', '=', 'convocatoria.carrera_id')
                    ->whereColumn('convocatoria.convocatoria_carrera_id', 'silabo.convocatoria_carrera_id')
                    ->where('carrera.facultad_id', $facultyId),
            ))
            ->when($period !== self::PERIOD_ANY || $periodId !== null, fn (Builder $query) => $query->whereExists(
                fn ($convocation) => $convocation->selectRaw('1')
                    ->from('convocatorias_carreras as convocatoria')
                    ->join('convocatorias_universidad as proceso', 'proceso.convocatoria_universidad_id', '=', 'convocatoria.convocatoria_universidad_id')
                    ->join('periodos_academicos as periodo', 'periodo.periodo_academico_id', '=', 'proceso.periodo_academico_id')
                    ->whereColumn('convocatoria.convocatoria_carrera_id', 'silabo.convocatoria_carrera_id')
                    // Actual = la convocatoria institucional más reciente; las demás van al historial.
                    ->when($period === self::PERIOD_CURRENT, fn ($q) => $q->where('proceso.convocatoria_universidad_id', SyllabusProcess::currentId()))
                    ->when($period === self::PERIOD_PAST, fn ($q) => $q->where('proceso.convocatoria_universidad_id', '!=', SyllabusProcess::currentId()))
                    ->when($periodId !== null, fn ($q) => $q->where('periodo.periodo_academico_id', $periodId)),
            ))
            ->when($handledBy !== null, fn (Builder $query) => $query->where(
                fn (Builder $handled) => $handled
                    ->where('envios_biblioteca.verificado_por', $handledBy)
                    ->orWhere(fn (Builder $returned) => $returned
                        ->where('revision.correccion_etapa', 'biblioteca')
                        ->where('revision.correccion_solicitada_por', $handledBy)),
            ))
            ->orderByDesc('revision.enviado_en');
    }

    /** @return array{state: string, outcome: string|null} */
    public function statusOf(LibrarySubmission $submission): array
    {
        $row = $this->query(withCarried: true)->where('envios_biblioteca.id', $submission->id)->first();
        $state = $row?->getAttribute('estado_envio');
        $state = is_string($state) ? $state : self::STATE_PENDING;

        return ['state' => $state, 'outcome' => self::outcome($state, $row?->getAttribute('resultado_envio'))];
    }

    /** Bibliotecario que lo atiende (el que lo verificó o devolvió antes); null si todavía nadie. Es un aviso, no un bloqueo. */
    public function responsibleOf(SyllabusRevision $revision): ?string
    {
        $row = $this->query(withCarried: true)->where('envios_biblioteca.revision_silabo_id', $revision->id)->first();
        $id = $row?->getAttribute('responsable_id');

        return is_string($id) ? $id : null;
    }

    public static function outcome(string $state, mixed $detail): ?string
    {
        return $state === self::STATE_HANDLED && is_string($detail) ? $detail : null;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $totals = DB::query()
            ->fromSub($this->query(period: self::PERIOD_CURRENT, latestOnly: true)->reorder()->toBase(), 'envios')
            ->selectRaw('resultado_envio, count(*) AS total')
            ->groupBy('resultado_envio')
            ->pluck('total', 'resultado_envio');
        $counts = array_fill_keys([self::STATE_PENDING, self::OUTCOME_SIGNED, self::OUTCOME_RETURNED, self::OUTCOME_REPLACED], 0);
        foreach ($totals as $outcome => $total) {
            if (isset($counts[$outcome])) {
                $counts[$outcome] = (int) $total;
            }
        }

        return $counts;
    }
}
