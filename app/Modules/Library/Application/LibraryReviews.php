<?php

namespace App\Modules\Library\Application;

use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class LibraryReviews
{
    public const STATE_PENDING = 'pendiente';

    public const STATE_TO_SIGN = 'por_firmar';

    public const STATE_SIGNED = 'firmado';

    public const STATE_COMMENTED = 'comentado';

    public const STATE_VOIDED = 'anulado';

    public const STATES = [self::STATE_PENDING, self::STATE_TO_SIGN, self::STATE_SIGNED, self::STATE_COMMENTED, self::STATE_VOIDED];

    private const STATE_SQL = <<<'SQL'
        CASE
            WHEN EXISTS (
                SELECT 1 FROM firmas_documento firma
                JOIN artefactos_exportacion artefacto ON artefacto.id = firma.artefacto_exportacion_id
                WHERE artefacto.revision_silabo_id = envios_biblioteca.revision_silabo_id
                  AND firma.rol_firma = 'biblioteca'
            ) THEN 'firmado'
            WHEN reapertura.origen_reapertura = 'biblioteca' THEN 'comentado'
            WHEN reapertura.id IS NOT NULL THEN 'anulado'
            WHEN envios_biblioteca.verificado_en IS NOT NULL THEN 'por_firmar'
            ELSE 'pendiente'
        END
        SQL;

    /** @return Builder<LibrarySubmission> */
    public function query(?string $state = null, ?string $search = null): Builder
    {
        return LibrarySubmission::query()
            ->join('revisiones_silabo as revision', 'revision.id', '=', 'envios_biblioteca.revision_silabo_id')
            ->join('silabos as silabo', 'silabo.id', '=', 'revision.silabo_id')
            ->join('aprobaciones as aprobacion', 'aprobacion.revision_silabo_id', '=', 'revision.id')
            ->leftJoin('reaperturas as reapertura', 'reapertura.aprobacion_id', '=', 'aprobacion.id')
            ->select('envios_biblioteca.*', 'revision.id as revision_id', 'revision.silabo_id as silabo_id', 'revision.numero_revision as numero_revision')
            ->selectRaw(self::STATE_SQL.' AS estado_envio')
            ->when($state !== null, fn (Builder $query) => $query->whereRaw('('.self::STATE_SQL.') = ?', [$state]))
            ->when($search !== null && $search !== '', fn (Builder $query) => $query->where(
                fn (Builder $outer) => $outer
                    ->whereRaw("silabo.datos_academicos_silabo->'subject'->>'name' ILIKE ?", ["%{$search}%"])
                    ->orWhereRaw("silabo.datos_academicos_silabo->'subject'->>'code' ILIKE ?", ["%{$search}%"]),
            ))
            ->orderByDesc('envios_biblioteca.enviado_en');
    }

    public function stateOf(LibrarySubmission $submission): string
    {
        $state = $this->query()->where('envios_biblioteca.id', $submission->id)->first()?->getAttribute('estado_envio');

        return is_string($state) ? $state : self::STATE_PENDING;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $totals = DB::query()
            ->fromSub($this->query()->reorder()->toBase(), 'envios')
            ->selectRaw('estado_envio, count(*) AS total')
            ->groupBy('estado_envio')
            ->pluck('total', 'estado_envio');
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($totals as $state => $total) {
            if (isset($counts[$state])) {
                $counts[$state] = (int) $total;
            }
        }

        return $counts;
    }
}
