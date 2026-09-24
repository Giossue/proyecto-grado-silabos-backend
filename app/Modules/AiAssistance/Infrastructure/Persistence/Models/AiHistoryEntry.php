<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Memoria de la IA (I-135): cada fila es un mensaje JSON dentro de una sesión.
 *
 * Sesiones:
 * - `silabo:{id}:campo:{campo}`: análisis de un campo (`tipo = analisis`) y decisiones
 *   de la persona sobre sus recomendaciones (`tipo = decision`).
 * - `silabo:{id}:revision`: revisiones integrales (`tipo = revision`).
 * - `silabo:{id}:usuario:{usuario}`: chat (`tipo = mensaje`) y ocultamientos.
 *
 * `mensaje_ia.entrada` es la entrada fijada e inmutable; un mensaje terminal no cambia
 * (trigger `proteger_historial_ia`).
 *
 * @property string $id
 * @property string $sesion_id
 * @property array<string, mixed> $mensaje_ia
 * @property CarbonImmutable $enviado_en
 */
class AiHistoryEntry extends Model
{
    use HasUuids;

    /** Estados que todavía admiten cambios. */
    public const OPEN_STATES = ['pendiente', 'en_ejecucion'];

    public $timestamps = false;

    protected $table = 'historial_ia';

    /** @var list<string> */
    protected $fillable = ['id', 'sesion_id', 'mensaje_ia'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mensaje_ia' => 'array', 'enviado_en' => 'immutable_datetime'];
    }

    public static function fieldSession(string $syllabusId, string $fieldId): string
    {
        return "silabo:{$syllabusId}:campo:{$fieldId}";
    }

    public static function reviewSession(string $syllabusId): string
    {
        return "silabo:{$syllabusId}:revision";
    }

    public static function chatSession(string $syllabusId, string $userId): string
    {
        return "silabo:{$syllabusId}:usuario:{$userId}";
    }

    /**
     * @param  Builder<AiHistoryEntry>  $query
     * @return Builder<AiHistoryEntry>
     */
    public function scopeSession(Builder $query, string $session): Builder
    {
        return $query->where('sesion_id', $session);
    }

    /**
     * @param  Builder<AiHistoryEntry>  $query
     * @return Builder<AiHistoryEntry>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('mensaje_ia->tipo', $type);
    }

    /**
     * Análisis de todos los campos de un sílabo.
     *
     * @param  Builder<AiHistoryEntry>  $query
     * @return Builder<AiHistoryEntry>
     */
    public function scopeFieldAnalysesOf(Builder $query, string $syllabusId): Builder
    {
        return $query->where('sesion_id', 'like', "silabo:{$syllabusId}:campo:%")
            ->where('mensaje_ia->tipo', 'analisis');
    }

    public function type(): string
    {
        return (string) ($this->mensaje_ia['tipo'] ?? '');
    }

    public function state(): string
    {
        return (string) ($this->mensaje_ia['estado'] ?? '');
    }

    public function isOpen(): bool
    {
        return in_array($this->state(), self::OPEN_STATES, true);
    }

    public function input(string $key): mixed
    {
        $input = $this->mensaje_ia['entrada'] ?? [];

        return is_array($input) ? ($input[$key] ?? null) : null;
    }

    /** Identificador o texto obligatorio de la entrada fijada. */
    public function inputString(string $key): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public function part(string $key): mixed
    {
        return $this->mensaje_ia[$key] ?? null;
    }

    /** @param array<string, mixed> $changes */
    public function amend(array $changes): void
    {
        $this->update(['mensaje_ia' => array_replace($this->mensaje_ia, $changes)]);
    }

    /** @return list<array<string, mixed>> */
    public function recommendations(): array
    {
        $items = $this->mensaje_ia['recomendaciones'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /** @return array<string, mixed>|null */
    public function recommendation(string $id): ?array
    {
        foreach ($this->recommendations() as $recommendation) {
            if (($recommendation['id'] ?? null) === $id) {
                return $recommendation;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function evidence(): array
    {
        $items = $this->input('evidencia');

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
