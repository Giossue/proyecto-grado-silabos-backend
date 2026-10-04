<?php

namespace App\Modules\Syllabus\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Concerns\ImmutableRecord;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $silabo_id
 * @property string|null $revision_anterior_id
 * @property int $numero_revision
 * @property string $clave_idempotencia
 * @property int $version_bloqueo_origen
 * @property array<string, mixed> $fotografia
 * @property string $huella_sha256
 * @property string $enviado_por
 * @property CarbonImmutable $enviado_en
 * @property string|null $correccion_justificacion
 * @property string|null $correccion_etapa
 * @property string|null $correccion_solicitada_por
 * @property CarbonImmutable|null $correccion_solicitada_en
 * @property-read Syllabus $syllabus
 */
class SyllabusRevision extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'fotografia' => 'contenido_enviado',
        'huella_sha256' => 'hash_contenido',
        'version_bloqueo_origen' => 'version_borrador',
        'clave_idempotencia' => 'clave_solicitud',
    ];

    use ImmutableRecord;

    public $timestamps = false;

    protected $table = 'revisiones_silabo';

    /** @var list<string> */
    protected $fillable = [
        'silabo_id', 'revision_anterior_id', 'numero_revision',
        'clave_idempotencia', 'version_bloqueo_origen', 'fotografia', 'huella_sha256',
        'enviado_por', 'enviado_en',
        'correccion_justificacion', 'correccion_etapa', 'correccion_solicitada_por', 'correccion_solicitada_en',
    ];

    /**
     * Solicitud de corrección: se registra una sola vez sobre la revisión; lo demás es inmutable.
     *
     * @return list<string>
     */
    public function writeOnceColumns(): array
    {
        return ['correccion_justificacion', 'correccion_etapa', 'correccion_solicitada_por', 'correccion_solicitada_en'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'numero_revision' => 'integer',
            'version_bloqueo_origen' => 'integer',
            'fotografia' => 'array',
            'enviado_en' => 'immutable_datetime',
            'correccion_solicitada_en' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Syllabus, $this> */
    public function syllabus(): BelongsTo
    {
        return $this->belongsTo(Syllabus::class, 'silabo_id');
    }

    /** @return BelongsTo<SyllabusRevision, $this> */
    public function previousRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_anterior_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    /** @return HasOne<Approval, $this> */
    public function approval(): HasOne
    {
        return $this->hasOne(Approval::class, 'revision_silabo_id');
    }

    /** @return BelongsTo<User, $this> */
    public function correctionRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'correccion_solicitada_por');
    }

    public function hasCorrectionRequest(): bool
    {
        return $this->correccion_solicitada_en !== null;
    }

    /** @return HasOne<CoordinationReview, $this> */
    public function coordinationReview(): HasOne
    {
        return $this->hasOne(CoordinationReview::class, 'revision_silabo_id');
    }

    /** @return HasOne<LibrarySubmission, $this> */
    public function librarySubmission(): HasOne
    {
        return $this->hasOne(LibrarySubmission::class, 'revision_silabo_id');
    }
}
