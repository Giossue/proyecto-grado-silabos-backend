<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Support\Database\ExplicitColumnBuilder;
use Illuminate\Support\Facades\DB;

/**
 * @extends ExplicitColumnBuilder<FieldDefinition>
 */
class FieldDefinitionBuilder extends ExplicitColumnBuilder
{
    private const BLOCK_COLUMNS = ['clave' => 'clave_campo_plantilla', 'etiqueta' => 'titulo_campo_plantilla'];

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_string($column) && isset(self::BLOCK_COLUMNS[$column])) {
            if (func_num_args() === 2) {
                $value = $operator;
                $operator = '=';
            }
            $blocks = DB::table('campos_plantilla')->select('campo_plantilla_id')->where(self::BLOCK_COLUMNS[$column], $operator, $value);

            return $this->whereIn('campo_plantilla_id', $blocks, $boolean);
        }

        return parent::where($column, $operator, $value, $boolean);
    }
}
