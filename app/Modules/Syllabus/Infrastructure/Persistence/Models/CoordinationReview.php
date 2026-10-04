<?php

namespace App\Modules\Syllabus\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aprobación de coordinación de una revisión (I-173): entre biblioteca y decanato.
 *
 * @property string $id
 * @property string $revision_silabo_id
 * @property string $revisado_por
 * @property CarbonImmutable $revisado_en
 * @property-read SyllabusRevision $revision
 * @property-read User $reviewer
 */
class CoordinationReview extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = ['id' => 'revision_coordinacion_id'];

    public $timestamps = false;

    protected $table = 'revisiones_coordinacion';

    protected $primaryKey = 'revision_coordinacion_id';

    /** @var list<string> */
    protected $fillable = ['revision_silabo_id', 'revisado_por', 'revisado_en'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revisado_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SyllabusRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(SyllabusRevision::class, 'revision_silabo_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }
}
