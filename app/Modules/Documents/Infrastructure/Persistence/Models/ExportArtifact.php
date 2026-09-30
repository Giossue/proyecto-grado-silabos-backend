<?php

namespace App\Modules\Documents\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use LogicException;

/**
 * @property string $id
 * @property string $revision_silabo_id
 * @property string|null $ejecucion_trabajo_id
 * @property string|null $objeto_docx_id
 * @property string|null $objeto_pdf_id
 * @property string $clave_idempotencia
 * @property string $estado
 * @property string $solicitado_por
 * @property CarbonImmutable $solicitado_en
 * @property CarbonImmutable|null $completado_en
 * @property-read SyllabusRevision $revision
 * @property-read Syllabus $syllabus
 */
class ExportArtifact extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'estado' => 'estado_exportacion',
        'objeto_docx_id' => 'archivo_word_id',
        'objeto_pdf_id' => 'archivo_pdf_id',
        'ejecucion_trabajo_id' => 'trabajo_id',
        'clave_idempotencia' => 'clave_solicitud',
    ];

    public $timestamps = false;

    protected $table = 'exportaciones_silabo';

    /** @var list<string> */
    protected $fillable = [
        'revision_silabo_id', 'ejecucion_trabajo_id',
        'objeto_docx_id', 'objeto_pdf_id',
        'clave_idempotencia', 'estado', 'solicitado_por',
        'solicitado_en', 'completado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['solicitado_en' => 'immutable_datetime', 'completado_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SyllabusRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(SyllabusRevision::class, 'revision_silabo_id');
    }

    /** @return HasOneThrough<Syllabus, SyllabusRevision, $this> */
    public function syllabus(): HasOneThrough
    {
        return $this->hasOneThrough(Syllabus::class, SyllabusRevision::class, 'id', 'id', 'revision_silabo_id', 'silabo_id');
    }

    /** @return BelongsTo<JobExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(JobExecution::class, 'trabajo_id');
    }

    /** @return BelongsTo<StoredObject, $this> */
    public function docxObject(): BelongsTo
    {
        return $this->belongsTo(StoredObject::class, 'archivo_word_id');
    }

    /** @return BelongsTo<StoredObject, $this> */
    public function pdfObject(): BelongsTo
    {
        return $this->belongsTo(StoredObject::class, 'archivo_pdf_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    protected static function booted(): void
    {
        static::updating(function (ExportArtifact $artifact): void {
            if ($artifact->getOriginal('estado_exportacion') === 'completado') {
                throw new LogicException('Un artefacto completado es inmutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('La retención no permite eliminar artefactos.'));
    }
}
