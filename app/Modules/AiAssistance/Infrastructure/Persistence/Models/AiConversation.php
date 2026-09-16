<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $silabo_id
 * @property string $usuario_id
 * @property CarbonImmutable|null $ocultada_en
 * @property CarbonImmutable $iniciada_en
 * @property-read Syllabus $syllabus
 * @property-read User $user
 * @property-read Collection<int, AiMessage> $messages
 */
class AiConversation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'conversaciones_ia';

    /** @var list<string> */
    protected $fillable = ['silabo_id', 'usuario_id', 'ocultada_en', 'iniciada_en'];

    protected function casts(): array
    {
        return ['ocultada_en' => 'immutable_datetime', 'iniciada_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Syllabus, $this> */
    public function syllabus(): BelongsTo
    {
        return $this->belongsTo(Syllabus::class, 'silabo_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** @return HasMany<AiMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversacion_ia_id')->orderBy('enviado_en');
    }
}
