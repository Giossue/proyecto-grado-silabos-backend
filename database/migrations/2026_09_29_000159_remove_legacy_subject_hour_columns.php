<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I-162: `horas_proyecto`, `horas_ap` y `horas_paec` venían del sistema anterior (I-11).
 * La malla vigente reparte las horas de cada materia solo en ACD, APE y AA; ninguna
 * pantalla las llenaba y todas las filas estaban en NULL. Idempotente.
 */
return new class extends Migration
{
    private const COLUMNS = ['horas_proyecto', 'horas_ap', 'horas_paec'];

    public function up(): void
    {
        $present = array_filter(self::COLUMNS, fn (string $column): bool => Schema::hasColumn('asignaturas', $column));
        if ($present === []) {
            return;
        }
        Schema::table('asignaturas', function (Blueprint $table) use ($present): void {
            $table->dropColumn(array_values($present));
        });
    }

    /** Devuelve las columnas vacías; el dato retirado no se reconstruye. */
    public function down(): void
    {
        Schema::table('asignaturas', function (Blueprint $table): void {
            if (! Schema::hasColumn('asignaturas', 'horas_proyecto')) {
                $table->decimal('horas_proyecto', 6, 2)->nullable();
            }
            if (! Schema::hasColumn('asignaturas', 'horas_ap')) {
                $table->decimal('horas_ap', 6, 2)->nullable();
            }
            if (! Schema::hasColumn('asignaturas', 'horas_paec')) {
                $table->decimal('horas_paec', 6, 2)->nullable();
            }
        });
    }
};
