<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE modalidad_estudio AS ENUM ('presencial', 'semipresencial', 'en_linea', 'a_distancia', 'hibrida')");
        DB::statement('ALTER TABLE carreras DROP CONSTRAINT IF EXISTS carreras_modalidad_check');
        DB::statement('ALTER TABLE asignaturas DROP CONSTRAINT IF EXISTS asignaturas_modalidad_check');
        DB::statement('ALTER TABLE carreras ALTER COLUMN modalidad_carrera TYPE modalidad_estudio USING modalidad_carrera::modalidad_estudio');
        DB::statement('ALTER TABLE asignaturas ALTER COLUMN modalidad_asignatura TYPE modalidad_estudio USING modalidad_asignatura::modalidad_estudio');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE carreras ALTER COLUMN modalidad_carrera TYPE varchar(20) USING modalidad_carrera::text');
        DB::statement('ALTER TABLE asignaturas ALTER COLUMN modalidad_asignatura TYPE varchar(20) USING modalidad_asignatura::text');
        DB::statement('DROP TYPE IF EXISTS modalidad_estudio');
    }
};
