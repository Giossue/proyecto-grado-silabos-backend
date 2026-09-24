<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Almacén vectorial de la IA (I-135): un fragmento de fuente académica con sus
 * metadatos (`fuente_academica_id`, `carrera_id`, `titulo`, `ruta_encabezados`,
 * `posicion`, páginas, bloques de origen y `huella`) y su embedding.
 *
 * @property string $id
 * @property string $contenido_documento
 * @property array<string, mixed> $metadatos_documento
 * @property string|null $embedding
 */
class AiDocument extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'documentos_ia';

    /** @var list<string> */
    protected $fillable = ['contenido_documento', 'metadatos_documento'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadatos_documento' => 'array'];
    }

    /**
     * @param  Builder<AiDocument>  $query
     * @param  array<int, string>  $sourceIds
     * @return Builder<AiDocument>
     */
    public function scopeFromSources(Builder $query, array $sourceIds): Builder
    {
        return $query->whereIn('metadatos_documento->fuente_academica_id', $sourceIds);
    }

    /**
     * @param  Builder<AiDocument>  $query
     * @return Builder<AiDocument>
     */
    public function scopeInSourceOrder(Builder $query): Builder
    {
        return $query->orderByRaw("metadatos_documento->>'fuente_academica_id'")
            ->orderByRaw("(metadatos_documento->>'posicion')::integer");
    }

    public function sourceId(): string
    {
        return (string) ($this->metadatos_documento['fuente_academica_id'] ?? '');
    }

    public function title(): ?string
    {
        $title = $this->metadatos_documento['titulo'] ?? null;

        return is_string($title) ? $title : null;
    }

    /** @return list<string> */
    public function headingPath(): array
    {
        $path = $this->metadatos_documento['ruta_encabezados'] ?? [];

        return is_array($path) ? array_values(array_filter($path, 'is_string')) : [];
    }

    public function fingerprint(): string
    {
        return (string) ($this->metadatos_documento['huella'] ?? '');
    }
}
