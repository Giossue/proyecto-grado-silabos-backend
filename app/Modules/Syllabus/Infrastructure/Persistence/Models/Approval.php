<?php

namespace App\Modules\Syllabus\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Concerns\ImmutableRecord;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $revision_silabo_id
 * @property string $clave_idempotencia
 * @property string $huella_sha256
 * @property string $aprobado_por
 * @property string|null $aprobacion_anterior_id
 * @property CarbonImmutable $aprobado_en
 * @property string|null $causa_reapertura
 * @property string|null $origen_reapertura
 * @property string|null $reabierta_por
 * @property CarbonImmutable|null $reabierta_en
 * @property string|null $clave_reapertura
 * @property-read SyllabusRevision $revision
 */
class Approval extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'huella_sha256' => 'hash_contenido',
        'clave_idempotencia' => 'clave_solicitud',
    ];

    use ImmutableRecord;

    public const ORIGIN_COORDINATION = 'coordinacion';

    public const ORIGIN_LIBRARY = 'biblioteca';

    public const ORIGIN_DEANERY = 'decanato';

    public $timestamps = false;

    protected $table = 'aprobaciones';

    /** @var list<string> */
    protected $fillable = [
        'revision_silabo_id', 'clave_idempotencia', 'huella_sha256',
        'aprobado_por', 'aprobado_en', 'aprobacion_anterior_id',
        'causa_reapertura', 'origen_reapertura', 'reabierta_por', 'reabierta_en', 'clave_reapertura',
    ];

    /**
     * Reapertura: se registra una sola vez sobre la aprobación vigente; lo demás es inmutable.
     *
     * @return list<string>
     */
    public function writeOnceColumns(): array
    {
        return ['causa_reapertura', 'origen_reapertura', 'reabierta_por', 'reabierta_en', 'clave_reapertura'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aprobado_en' => 'immutable_datetime', 'reabierta_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SyllabusRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(SyllabusRevision::class, 'revision_silabo_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    /** @return BelongsTo<Approval, $this> */
    public function baseApproval(): BelongsTo
    {
        return $this->belongsTo(Approval::class, 'aprobacion_anterior_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reabierta_por');
    }

    public function isReopened(): bool
    {
        return $this->reabierta_en !== null;
    }
}
