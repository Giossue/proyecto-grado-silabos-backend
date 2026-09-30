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
 * @property string $aprobacion_id
 * @property string $revision_aprobada_id
 * @property string $clave_idempotencia
 * @property string $causa
 * @property string $origen_reapertura
 * @property string $reabierto_por
 * @property CarbonImmutable $reabierto_en
 */
class Reopening extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = ['clave_idempotencia' => 'clave_solicitud'];

    use ImmutableRecord;

    public const ORIGIN_COORDINATION = 'coordinacion';

    public const ORIGIN_LIBRARY = 'biblioteca';

    public $timestamps = false;

    protected $table = 'reaperturas';

    /** @var list<string> */
    protected $fillable = [
        'aprobacion_id', 'revision_aprobada_id', 'clave_idempotencia',
        'causa', 'reabierto_por', 'reabierto_en', 'origen_reapertura',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['origen_reapertura' => self::ORIGIN_COORDINATION];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reabierto_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class, 'aprobacion_id');
    }

    /** @return BelongsTo<SyllabusRevision, $this> */
    public function approvedRevision(): BelongsTo
    {
        return $this->belongsTo(SyllabusRevision::class, 'revision_aprobada_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reabierto_por');
    }
}
