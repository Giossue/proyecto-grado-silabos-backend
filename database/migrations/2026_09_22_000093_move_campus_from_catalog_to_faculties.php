<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private const LOCATIONS = [
        'Campus Matriz Dr. Gabriel Galarza López',
        'Campus Laguacoto',
        'Campus CENI (Centro de Eventos Nacionales e Internacionales)',
        'Extensión San Miguel',
        'Sede Chimbo',
    ];

    public function up(): void
    {
        $campusById = $this->campusMapping();
        $facultyLocations = $this->facultyLocations($campusById);
        $this->assertScheduledCampusValuesKnown($campusById);

        Schema::table('facultades', function (Blueprint $table): void {
            $table->string('campus_facultad')->nullable();
        });
        Schema::table('programaciones_asignatura', function (Blueprint $table): void {
            $table->string('campus_programacion')->nullable();
        });

        foreach ($facultyLocations as $facultyId => $location) {
            DB::table('facultades')->where('id', $facultyId)->update(['campus_facultad' => $location]);
        }
        foreach ($campusById as $campusId => $location) {
            DB::table('programaciones_asignatura')
                ->where('campus_id', $campusId)
                ->update(['campus_programacion' => $location]);
        }

        DB::statement('ALTER TABLE facultades ALTER COLUMN campus_facultad SET NOT NULL');
        DB::statement('ALTER TABLE programaciones_asignatura ALTER COLUMN campus_programacion SET NOT NULL');
        DB::statement('ALTER TABLE programaciones_asignatura DROP CONSTRAINT programaciones_asignatura_campus_id_foreign');
        DB::statement('ALTER TABLE carreras DROP CONSTRAINT carreras_campus_id_foreign');
        Schema::table('programaciones_asignatura', function (Blueprint $table): void {
            $table->dropColumn('campus_id');
        });
        Schema::table('carreras', function (Blueprint $table): void {
            $table->dropColumn('campus_id');
        });
        Schema::drop('campus');

        DB::statement("ALTER TABLE facultades ADD CONSTRAINT facultades_campus_facultad_check CHECK (campus_facultad IN ('".implode("','", self::LOCATIONS)."'))");
        DB::statement("ALTER TABLE programaciones_asignatura ADD CONSTRAINT programaciones_asignatura_campus_programacion_check CHECK (campus_programacion IN ('".implode("','", self::LOCATIONS)."'))");
    }

    public function down(): void
    {
        throw new RuntimeException('I-106 retira información de código e identidad del catálogo campus; restaure desde el respaldo previo a la migración.');
    }

    /** @return array<string, string> */
    private function campusMapping(): array
    {
        $mapping = [];
        foreach (DB::table('campus')->get(['id', 'codigo_campus', 'nombre_campus']) as $record) {
            $codeLocation = $this->identify((string) $record->codigo_campus);
            $nameLocation = $this->identify((string) $record->nombre_campus);
            if ($codeLocation !== null && $nameLocation !== null && $codeLocation !== $nameLocation) {
                throw new RuntimeException("El campus {$record->id} tiene código y nombre que apuntan a opciones distintas.");
            }

            $location = $codeLocation ?? $nameLocation;
            if ($location === null) {
                throw new RuntimeException("El campus {$record->id} no coincide con ninguna de las cinco opciones confirmadas.");
            }

            $mapping[(string) $record->id] = $location;
        }

        return $mapping;
    }

    /** @param array<string, string> $campusById
     * @return array<string, string>
     */
    private function facultyLocations(array $campusById): array
    {
        $locationsByFaculty = [];
        foreach (DB::table('carreras')->whereNotNull('campus_id')->get(['id', 'facultad_id', 'campus_id']) as $career) {
            $facultyId = (string) $career->facultad_id;
            $campusId = (string) $career->campus_id;
            if (! isset($campusById[$campusId])) {
                throw new RuntimeException("La carrera {$career->id} refiere a un campus que no está en las cinco opciones confirmadas.");
            }
            $locationsByFaculty[$facultyId][$campusById[$campusId]] = true;
        }

        $facultyLocations = [];
        foreach (DB::table('facultades')->get(['id', 'nombre_facultad']) as $faculty) {
            $facultyId = (string) $faculty->id;
            $locations = $locationsByFaculty[$facultyId] ?? [];
            if ($locations === []) {
                throw new RuntimeException('La facultad «'.$faculty->nombre_facultad.'» no tiene una carrera con campus asignado; concilie su campus antes de migrar.');
            }
            if (count($locations) > 1) {
                throw new RuntimeException('La facultad «'.$faculty->nombre_facultad.'» combina campus distintos; concilie sus carreras antes de migrar.');
            }
            $facultyLocations[$facultyId] = array_key_first($locations);
        }

        return $facultyLocations;
    }

    /** @param array<string, string> $campusById */
    private function assertScheduledCampusValuesKnown(array $campusById): void
    {
        $unknown = DB::table('programaciones_asignatura')
            ->where(fn ($query) => $query
                ->whereNull('campus_id')
                ->orWhereNotIn('campus_id', array_keys($campusById)))
            ->exists();
        if ($unknown) {
            throw new RuntimeException('Una programación refiere a un campus fuera de las cinco opciones confirmadas.');
        }
    }

    private function identify(string $value): ?string
    {
        $normalized = Str::ascii(Str::lower(trim($value)));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';

        return match (true) {
            str_contains($normalized, 'matriz') => self::LOCATIONS[0],
            str_contains($normalized, 'laguacoto') => self::LOCATIONS[1],
            str_contains($normalized, 'ceni'), str_contains($normalized, 'centro de eventos') => self::LOCATIONS[2],
            str_contains($normalized, 'san miguel') => self::LOCATIONS[3],
            str_contains($normalized, 'chimbo') => self::LOCATIONS[4],
            default => null,
        };
    }
};
