<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property CampusLocation $campus */
class Faculty extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'facultad_id',
        'nombre' => 'nombre_facultad',
        'activo' => 'facultad_activa',
        'campus' => 'campus_facultad',
        'logo_objeto_id' => 'logo_archivo_id',
    ];

    public $timestamps = false;

    protected $table = 'facultades';

    protected $primaryKey = 'facultad_id';

    /** @var list<string> */
    protected $fillable = ['nombre', 'campus', 'logo_objeto_id', 'activo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['campus' => CampusLocation::class];
    }

    /** @return BelongsTo<StoredObject, $this> */
    public function logoObject(): BelongsTo
    {
        return $this->belongsTo(StoredObject::class, 'logo_archivo_id');
    }

    /** @return HasMany<Career, $this> */
    public function careers(): HasMany
    {
        return $this->hasMany(Career::class, 'facultad_id');
    }
}
