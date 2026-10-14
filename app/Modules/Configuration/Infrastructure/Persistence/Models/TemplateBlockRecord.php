<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $campo_plantilla_id
 * @property string $clave_campo_plantilla
 * @property string $titulo_campo_plantilla
 */
class TemplateBlockRecord extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'campos_plantilla';

    protected $primaryKey = 'campo_plantilla_id';

    protected $keyType = 'string';
}
