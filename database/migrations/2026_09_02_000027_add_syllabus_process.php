<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procesos_silabos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 180);
            $table->foreignUuid('version_plantilla_id')->constrained('versiones_plantilla')->restrictOnDelete();
            $table->timestampTz('inicia_en');
            $table->timestampTz('entrega_en');
            $table->string('estado', 20)->default('preparacion');
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('abierto_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('abierto_en')->nullable();
            $table->timestampTz('pausado_en')->nullable();
            $table->timestampTz('cerrado_en')->nullable();
            $table->timestampTz('creado_en')->nullable();
            $table->timestampTz('actualizado_en')->nullable();
            $table->index('estado', 'procesos_silabos_estado_idx');
        });

        DB::statement("ALTER TABLE procesos_silabos ADD CONSTRAINT procesos_silabos_estado_check CHECK (estado IN ('preparacion', 'abierto', 'pausado', 'cerrado'))");
        DB::statement('ALTER TABLE procesos_silabos ADD CONSTRAINT procesos_silabos_fechas_check CHECK (entrega_en > inicia_en)');
        DB::statement("CREATE UNIQUE INDEX procesos_silabos_en_curso_unico ON procesos_silabos ((1)) WHERE estado IN ('abierto', 'pausado')");

        Schema::table('convocatorias', function (Blueprint $table): void {
            $table->foreignUuid('proceso_id')->nullable()->after('carrera_id')
                ->constrained('procesos_silabos')->restrictOnDelete();
        });

        $this->backfillProcesses();

        DB::statement('ALTER TABLE convocatorias ALTER COLUMN proceso_id SET NOT NULL');
        DB::statement('ALTER TABLE convocatorias DROP CONSTRAINT IF EXISTS convocatorias_estado_check');
        DB::statement("ALTER TABLE convocatorias ADD CONSTRAINT convocatorias_estado_check CHECK (estado IN ('preparacion', 'abierta', 'pausada', 'cerrada'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE convocatorias SET estado = 'abierta' WHERE estado = 'pausada'");
        DB::statement('ALTER TABLE convocatorias DROP CONSTRAINT IF EXISTS convocatorias_estado_check');
        DB::statement("ALTER TABLE convocatorias ADD CONSTRAINT convocatorias_estado_check CHECK (estado IN ('preparacion', 'abierta', 'cerrada'))");

        Schema::table('convocatorias', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proceso_id');
        });

        Schema::dropIfExists('procesos_silabos');
    }

    private function backfillProcesses(): void
    {
        $convocations = DB::table('convocatorias')
            ->whereNull('proceso_id')
            ->orderBy('creado_en')
            ->get();
        $runningByTemplate = [];

        foreach ($convocations as $convocation) {
            $deadlines = DB::table('fechas_limite_convocatoria')
                ->where('convocatoria_id', $convocation->id)
                ->pluck('vence_en', 'etapa');
            $startsAt = $deadlines['inicio'] ?? $convocation->creado_en ?? now();
            $dueAt = $deadlines['borrador'] ?? null;
            if ($dueAt === null || strtotime((string) $dueAt) <= strtotime((string) $startsAt)) {
                $dueAt = date('Y-m-d H:i:sP', strtotime((string) $startsAt) + 30 * 86400);
            }

            $state = match ($convocation->estado) {
                'abierta' => 'abierto',
                'cerrada' => 'cerrado',
                default => 'preparacion',
            };

            if ($state === 'abierto' && isset($runningByTemplate[$convocation->version_plantilla_id])) {
                DB::table('convocatorias')
                    ->where('id', $convocation->id)
                    ->update(['proceso_id' => $runningByTemplate[$convocation->version_plantilla_id]]);

                continue;
            }

            if ($state === 'abierto' && $runningByTemplate !== []) {
                $state = 'cerrado';
            }

            $processId = (string) Str::uuid();
            DB::table('procesos_silabos')->insert([
                'id' => $processId,
                'nombre' => $convocation->nombre,
                'version_plantilla_id' => $convocation->version_plantilla_id,
                'inicia_en' => $startsAt,
                'entrega_en' => $dueAt,
                'estado' => $state,
                'creado_por' => $convocation->creado_por,
                'abierto_por' => $convocation->abierto_por,
                'abierto_en' => $convocation->abierto_en,
                'cerrado_en' => $state === 'cerrado' ? ($convocation->cerrado_en ?? now()) : null,
                'creado_en' => $convocation->creado_en,
                'actualizado_en' => now(),
            ]);
            if ($state === 'abierto') {
                $runningByTemplate[$convocation->version_plantilla_id] = $processId;
            }

            DB::table('convocatorias')->where('id', $convocation->id)->update(['proceso_id' => $processId]);
        }
    }
};
