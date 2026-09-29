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
        Schema::table('ejecuciones_trabajo', function (Blueprint $table): void {
            $table->jsonb('entrada_ejecucion_trabajo')->nullable()->after('recurso_id');
        });

        $this->migrateOutboxEvents();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_entrada_ejecucion_trabajo() RETURNS trigger AS $$
            BEGIN
                IF NEW.entrada_ejecucion_trabajo IS DISTINCT FROM OLD.entrada_ejecucion_trabajo THEN
                    RAISE EXCEPTION 'La entrada de una ejecución de trabajo es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER proteger_entrada_ejecucion_trabajo
            BEFORE UPDATE ON ejecuciones_trabajo
            FOR EACH ROW EXECUTE FUNCTION proteger_entrada_ejecucion_trabajo();
            SQL);

        DB::unprepared('DROP FUNCTION IF EXISTS proteger_contenido_evento_saliente() CASCADE');
        Schema::dropIfExists('eventos_salientes');
    }

    public function down(): void
    {
        throw new RuntimeException('I-90 consolida el outbox en ejecuciones_trabajo; restaure el respaldo previo para revertirla.');
    }

    private function migrateOutboxEvents(): void
    {
        if (! Schema::hasTable('eventos_salientes')) {
            return;
        }

        foreach (DB::table('eventos_salientes')->orderBy('ocurrido_en')->get() as $event) {
            $content = json_decode((string) $event->contenido_evento_saliente, true);
            $content = is_array($content) ? $content : [];
            $recipientIds = array_values(array_filter(
                $content['recipient_ids'] ?? [],
                static fn (mixed $value): bool => is_string($value),
            ));
            $revisionNumber = is_int($content['revision_number'] ?? null)
                ? $content['revision_number']
                : null;
            $state = match ($event->estado_evento_saliente) {
                'pendiente' => 'pendiente',
                'en_proceso' => 'en_ejecucion',
                'procesado' => 'completada',
                'fallido' => 'fallida',
                default => 'pendiente',
            };
            $terminal = in_array($state, ['completada', 'fallida'], true);
            $input = json_encode([
                'tipo_evento' => $event->tipo_evento,
                'destinatarios_ids' => $recipientIds,
                'numero_revision' => $revisionNumber,
                'silabo_id' => $event->agregado_id,
                'clave_deduplicacion' => $event->clave_deduplicacion,
                'ocurrido_en' => $event->ocurrido_en,
                'disponible_en' => $event->disponible_en,
                'evento_saliente_id' => $event->id,
            ], JSON_THROW_ON_ERROR);
            $execution = DB::table('ejecuciones_trabajo')
                ->where('tipo_recurso', 'evento_saliente')
                ->where('recurso_id', $event->id)
                ->first();

            if ($execution === null) {
                DB::table('ejecuciones_trabajo')->insert([
                    'id' => (string) Str::uuid(),
                    'tipo_ejecucion_trabajo' => 'notificacion.interna',
                    'estado_ejecucion_trabajo' => $state,
                    'clave_idempotencia' => 'notificacion.saliente:'.$event->id,
                    'correlacion_id' => (string) Str::uuid(),
                    'intentos' => (int) $event->intentos,
                    'progreso' => $state === 'completada' ? 100 : 0,
                    'resultado_ejecucion_trabajo' => null,
                    'codigo_error' => $event->codigo_error,
                    'mensaje_error' => $event->mensaje_error,
                    'iniciado_en' => $terminal ? $event->ocurrido_en : null,
                    'finalizado_en' => $terminal ? ($event->procesado_en ?? $event->ocurrido_en) : null,
                    'encolado_en' => $event->ocurrido_en,
                    'cola' => 'notificaciones',
                    'tipo_recurso' => 'silabo',
                    'recurso_id' => $event->agregado_id,
                    'entrada_ejecucion_trabajo' => $input,
                    'intentos_maximos' => 5,
                ]);

                continue;
            }

            DB::table('ejecuciones_trabajo')
                ->where('id', $execution->id)
                ->update([
                    'tipo_ejecucion_trabajo' => 'notificacion.interna',
                    'estado_ejecucion_trabajo' => $state,
                    'intentos' => max((int) $execution->intentos, (int) $event->intentos),
                    'codigo_error' => $event->codigo_error,
                    'mensaje_error' => $event->mensaje_error,
                    'finalizado_en' => $terminal ? ($event->procesado_en ?? $event->ocurrido_en) : null,
                    'tipo_recurso' => 'silabo',
                    'recurso_id' => $event->agregado_id,
                    'entrada_ejecucion_trabajo' => $input,
                    'intentos_maximos' => 5,
                    'progreso' => $state === 'completada' ? 100 : (int) $execution->progreso,
                ]);
        }
    }
};
