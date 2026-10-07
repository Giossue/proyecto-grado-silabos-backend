<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Modules\Configuration\Domain\FieldTypes\FieldType;
use App\Modules\Configuration\Domain\FieldTypes\FieldTypeRegistry;
use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $plantilla_id
 * @property string $campo_plantilla_id
 * @property string $clave
 * @property string $etiqueta
 * @property string|null $ayuda
 * @property string $tipo Tipo de la plantilla; mientras dure la compatibilidad (I-195, etapa 8) devuelve la clase anterior si existe
 * @property string|null $clase_anterior
 * @property bool $heredado
 * @property string|null $origen_maestro
 * @property bool $editable_docente
 * @property list<mixed>|null $opciones
 * @property array<string, mixed>|null $mostrar_si
 * @property int $posicion
 * @property-read TemplateBlock|null $block
 */
class FieldDefinition extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'definicion_campo_id',
        'clave' => 'clave_definicion_campo',
        'etiqueta' => 'etiqueta_definicion_campo',
        'ayuda' => 'ayuda_definicion_campo',
        'tipo' => 'tipo_definicion_campo',
        'clase_anterior' => 'clase_anterior_definicion_campo',
        'opciones' => 'opciones_definicion_campo',
        'posicion' => 'posicion_definicion_campo',
        'heredado' => 'heredado_definicion_campo',
        'origen_maestro' => 'origen_dato_definicion_campo',
        'editable_docente' => 'editable_docente_definicion_campo',
        'mostrar_si' => 'condicion_definicion_campo',
    ];

    /** Origen de los campos que se llenan una vez por asignatura (ficha) y el sílabo hereda. */
    public const ORIGIN_PROFILE = 'ficha';

    /** Origen de la formación y experiencia: la escribe el docente en su perfil (I-190). */
    public const ORIGIN_TEACHER = 'perfil_docente';

    public $timestamps = false;

    protected $table = 'definiciones_campo';

    protected $primaryKey = 'definicion_campo_id';

    /** @var list<string> */
    protected $fillable = [
        'plantilla_id',
        'campo_plantilla_id',
        'clave',
        'etiqueta',
        'ayuda',
        'tipo',
        'clase_anterior',
        'heredado',
        'origen_maestro',
        'editable_docente',
        'opciones',
        'mostrar_si',
        'posicion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'heredado' => 'boolean',
            'editable_docente' => 'boolean',
            'opciones' => 'array',
            'mostrar_si' => 'array',
            'posicion' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $field): void {
            $legacy = $field->getAttributes()['clase_anterior_definicion_campo'] ?? null;
            if ($legacy === null) {
                if (! isset($field->getAttributes()['tipo_definicion_campo'])) {
                    $field->setAttribute('tipo', 'texto');
                }

                return;
            }
            $fromProfile = $field->heredado && $field->origen_maestro === self::ORIGIN_PROFILE;
            $field->attributes['tipo_definicion_campo'] = match (true) {
                $legacy === 'texto_libre' && $fromProfile => 'ficha',
                $legacy === 'referencia_maestra' => 'automatico',
                default => 'texto',
            };
        });
        static::addGlobalScope('vigente_en_plantilla', fn (Builder $query) => $query->where('vigente_en_plantilla', true));
    }

    /** Clases anteriores que se guardan como `texto` (compatibilidad temporal, deuda de la etapa 8 de I-195). */
    private const LEGACY_CLASSES = [
        'texto_corto', 'texto_largo', 'texto_libre', 'numero', 'fecha', 'seleccion_unica', 'seleccion_multiple',
        'booleano', 'repetible', 'calculo', 'referencia_maestra',
    ];

    public function getAttribute($key): mixed
    {
        if ($key === 'tipo') {
            return parent::getAttribute('clase_anterior_definicion_campo') ?? parent::getAttribute('tipo_definicion_campo');
        }

        return parent::getAttribute($this->mappedColumn($key));
    }

    public function setAttribute($key, $value): static
    {
        // Compatibilidad: un tipo antiguo se guarda como clase anterior y `saving` deriva el tipo real.
        if ($key === 'tipo' && in_array($value, self::LEGACY_CLASSES, true)) {
            return parent::setAttribute('clase_anterior_definicion_campo', $value);
        }
        if ($key === 'tipo') {
            parent::setAttribute('clase_anterior_definicion_campo', null);
        }

        return parent::setAttribute($this->mappedColumn($key), $value);
    }

    /** Tipo del registro (texto, ficha, tabla, automatico), independiente de la clase anterior. */
    public function fieldType(): FieldType
    {
        return app(FieldTypeRegistry::class)->get((string) parent::getAttribute('tipo_definicion_campo'));
    }

    /** Configuración propia del tipo, guardada en `opciones` cuando es un objeto. */
    public function typeConfig(): array
    {
        $options = $this->opciones;
        $config = is_array($options) && ! array_is_list($options) ? $options : [];
        if ($this->fieldType()->key() === 'ficha' && ! array_key_exists('adjustable', $config)) {
            $config['adjustable'] = (bool) $this->editable_docente;
        }

        return $config;
    }

    /** Verdadero mientras el campo conserva una clase anterior (selección, número, etc.). */
    public function hasLegacyClass(): bool
    {
        return parent::getAttribute('clase_anterior_definicion_campo') !== null;
    }

    public function fromProfile(): bool
    {
        return $this->heredado && $this->origen_maestro === self::ORIGIN_PROFILE;
    }

    /** @return BelongsTo<SyllabusTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SyllabusTemplate::class, 'plantilla_id');
    }

    public function getBlockAttribute(): ?TemplateBlock
    {
        $template = $this->relationLoaded('template')
            ? $this->getRelation('template')
            : $this->template()->first();

        return $template instanceof SyllabusTemplate
            ? $template->block($this->campo_plantilla_id)
            : null;
    }

    public function displayName(): string
    {
        $label = trim((string) $this->etiqueta);
        if ($label !== '') {
            return $label;
        }
        $block = $this->block;
        $blockTitle = trim((string) $block?->titulo);

        return $blockTitle !== '' ? $blockTitle : trim((string) $block?->section->titulo);
    }
}
