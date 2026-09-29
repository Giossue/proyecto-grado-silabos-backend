<?php

namespace App\Modules\Syllabus\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $silabo_id
 * @property string $usuario_id
 * @property string $asignacion_rol_id
 * @property string $asignacion_paralelo_id
 * @property string $paralelo_id
 * @property-read Syllabus $syllabus
 * @property-read User $user
 */
class CurrentSyllabusTeacher extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'asignacion_paralelo_id';

    protected $keyType = 'string';

    protected $table = 'docentes_vigentes_silabo';

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

    /** @return BelongsTo<RoleAssignment, $this> */
    public function roleAssignment(): BelongsTo
    {
        return $this->belongsTo(RoleAssignment::class, 'asignacion_rol_id');
    }

    /** @return BelongsTo<TeacherAssignment, $this> */
    public function teacherAssignment(): BelongsTo
    {
        return $this->belongsTo(TeacherAssignment::class, 'asignacion_paralelo_id');
    }

    /** @param  Builder<CurrentSyllabusTeacher>  $query */
    public function scopeWithActiveUser(Builder $query): void
    {
        $query->whereHas('user', fn (Builder $user): Builder => $user->where('activo', true));
    }
}
