<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La unidad de organización curricular la fija el Reglamento de Régimen Académico
 * (básica, profesional, integración curricular): deja de ser texto libre y pasa a
 * valores fijos con CHECK, igual que la modalidad (I-37). Lo existente se traduce por
 * el texto guardado; lo que no se reconoce queda vacío para que Coordinación lo corrija.
 */
return new class extends Migration
{
    private const COLUMN = 'unidad_organizativa_curricular_asignatura';

    private const CHECK = 'asignaturas_unidad_organizativa_curricular_check';

    public function up(): void
    {
        $column = self::COLUMN;
        DB::statement(<<<SQL
            UPDATE asignaturas SET {$column} = CASE
                WHEN lower({$column}) LIKE '%b_sica%' THEN 'basica'
                WHEN lower({$column}) LIKE '%profesional%' THEN 'profesional'
                WHEN lower({$column}) LIKE '%integraci_n%' OR lower({$column}) LIKE '%titulaci_n%' THEN 'integracion_curricular'
                ELSE NULL
            END
        SQL);
        Schema::table('asignaturas', function (Blueprint $table): void {
            $table->string(self::COLUMN, 30)->nullable()->change();
        });
        $check = self::CHECK;
        DB::statement(<<<SQL
            ALTER TABLE asignaturas ADD CONSTRAINT {$check}
            CHECK ({$column} IN ('basica', 'profesional', 'integracion_curricular'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE asignaturas DROP CONSTRAINT IF EXISTS '.self::CHECK);
        $column = self::COLUMN;
        DB::statement(<<<SQL
            UPDATE asignaturas SET {$column} = CASE {$column}
                WHEN 'basica' THEN 'Unidad básica'
                WHEN 'profesional' THEN 'Unidad profesional'
                WHEN 'integracion_curricular' THEN 'Unidad de integración curricular'
            END
        SQL);
        Schema::table('asignaturas', function (Blueprint $table): void {
            $table->string(self::COLUMN, 80)->nullable()->change();
        });
    }
};
