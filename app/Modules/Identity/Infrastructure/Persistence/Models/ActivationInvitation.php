<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $usuario_id
 * @property string $hash_activacion
 * @property Carbon $expira_en
 * @property Carbon|null $usada_en
 * @property Carbon|null $invalidada_en
 * @property string|null $emitida_por_usuario_id
 * @property Carbon $creada_en
 */
class ActivationInvitation extends Model
{
    use HasUuids;

    public const CREATED_AT = 'creada_en';

    public const UPDATED_AT = null;

    protected $table = 'invitaciones_activacion';

    protected $fillable = [
        'usuario_id',
        'hash_activacion',
        'expira_en',
        'usada_en',
        'invalidada_en',
        'emitida_por_usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'expira_en' => 'datetime',
            'usada_en' => 'datetime',
            'invalidada_en' => 'datetime',
            'creada_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** @return Attribute<bool, never> */
    protected function vigente(): Attribute
    {
        return Attribute::get(fn (): bool => $this->usada_en === null
            && $this->invalidada_en === null
            && $this->expira_en->isFuture());
    }
}
