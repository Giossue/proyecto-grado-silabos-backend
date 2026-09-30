<?php

namespace App\Modules\Library\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Concerns\ImmutableRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $artefacto_exportacion_id
 * @property string $enviado_por
 * @property CarbonImmutable $enviado_en
 * @property-read ExportArtifact $artifact
 * @property-read User $sender
 */
class LibrarySubmission extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $timestamps = false;

    protected $table = 'envios_biblioteca';

    /** @var list<string> */
    protected $fillable = ['artefacto_exportacion_id', 'enviado_por', 'enviado_en'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enviado_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExportArtifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ExportArtifact::class, 'artefacto_exportacion_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
