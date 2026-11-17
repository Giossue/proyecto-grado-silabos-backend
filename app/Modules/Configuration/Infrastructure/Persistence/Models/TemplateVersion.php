<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $plantilla_id
 * @property int $numero
 * @property array<string, mixed> $contenido
 * @property string|null $guardada_por
 * @property CarbonImmutable $guardada_en
 * @property-read User|null $author
 */
class TemplateVersion extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'version_plantilla_id',
        'numero' => 'numero_version_plantilla',
        'contenido' => 'contenido_version_plantilla',
    ];

    public $timestamps = false;

    protected $table = 'versiones_plantilla';

    protected $primaryKey = 'version_plantilla_id';

    /** @var list<string> */
    protected $fillable = ['plantilla_id', 'numero', 'contenido', 'guardada_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['numero' => 'integer', 'contenido' => 'array', 'guardada_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardada_por', 'usuario_id');
    }
}
