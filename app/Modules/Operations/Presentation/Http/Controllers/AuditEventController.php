<?php

namespace App\Modules\Operations\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\AuditTargets;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Operations\Infrastructure\Spreadsheets\AuditWorkbook;
use App\Modules\Operations\Presentation\Http\Requests\ViewAuditEventsRequest;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditEventController extends Controller
{
    /** Estados y roles guardados como código, en palabras: «en_revision» → «En revisión». */
    private const VALUE_LABELS = [
        'sin_iniciar' => 'Sin iniciar', 'borrador' => 'Borrador', 'en_revision' => 'En revisión',
        'correccion_solicitada' => 'Corrección solicitada', 'aprobado' => 'Aprobado',
        'docente' => 'Docente', 'coordinador' => 'Coordinador', 'coordinacion' => 'Coordinación', 'biblioteca' => 'Biblioteca',
    ];

    public function index(ViewAuditEventsRequest $request, AuditTargets $targets): JsonResponse
    {
        $query = $this->filtered($request);
        $page = $query->latest('ocurrido_en')->paginate(20)->withQueryString();
        $described = $targets->describe($page->getCollection());

        $payload = [
            'filters' => [
                'technical' => $request->boolean('technical'),
                'action' => $request->string('action')->toString(),
                'result' => $request->string('result')->toString(),
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
                'period' => $request->string('period')->toString(),
                'role' => $request->string('role')->toString(),
                'career' => $request->string('career')->toString(),
            ],
            'action_options' => $this->actionOptions(),
            'period_options' => AcademicPeriod::query()->orderByDesc('fecha_inicio')->get(['id', 'codigo'])
                ->map(fn (AcademicPeriod $item): array => ['value' => $item->id, 'label' => $item->codigo])->values()->all(),
            'role_options' => array_map(fn (RoleCode $item): array => ['value' => $item->value, 'label' => $item->label()], RoleCode::cases()),
            'career_options' => Career::query()->orderBy('nombre')->orderBy('codigo_carrera')->get()
                ->map(fn (Career $item): array => ['value' => $item->id, 'label' => $item->label()])->values()->all(),
            'events' => $page
                ->through(fn (AuditEvent $event): array => [
                    'id' => $event->id,
                    'action' => $this->actionLabel($event->accion),
                    'resource' => $this->resourceLabel($event->tipo_recurso),
                    'target' => $described[$event->id] ?? '',
                    'result' => $event->resultado,
                    'actor' => $this->actorName($event),
                    'role' => $event->actor_rol ?? $event->roleAssignment?->role->nombre_rol,
                    'career' => $event->actor_carrera ?? $event->roleAssignment?->career?->label(),
                    'details' => $this->safeDetails($event->metadatos),
                    'occurred_at' => $event->ocurrido_en->toIso8601String(),
                ]),
        ];

        return response()->json($payload);
    }

    /**
     * Excel con los eventos de los filtros elegidos (normalmente un período): hoja «Eventos», ordenada por fecha,
     * y hoja «Resumen» con cuántas veces se hizo cada acción en cada carrera.
     */
    public function export(ViewAuditEventsRequest $request, AuditTargets $targets, AuditWorkbook $workbook): StreamedResponse
    {
        $rows = [];
        $this->filtered($request)->orderBy('ocurrido_en')->chunk(500, function ($events) use (&$rows, $targets): void {
            $described = $targets->describe($events);
            foreach ($events as $event) {
                $rows[] = [
                    'occurred_at' => $event->ocurrido_en->setTimezone(InstitutionDate::timezone()),
                    'actor' => $this->actorName($event),
                    'role' => $event->actor_rol ?? $event->roleAssignment?->role->nombre_rol ?? '',
                    'career' => $event->actor_carrera ?? $event->roleAssignment?->career?->label() ?? '',
                    'action' => $this->actionLabel($event->accion),
                    'target' => ($described[$event->id] ?? '') !== '' ? $described[$event->id] : $this->resourceLabel($event->tipo_recurso),
                    'details' => implode('; ', array_map(fn (array $detail): string => "{$detail['label']}: ".$this->detailText($detail['value']), $this->safeDetails($event->metadatos))),
                    'result' => $event->resultado === 'exito' ? 'Éxito' : 'Fallido',
                ];
            }
        });
        $periodId = $request->string('period')->toString();
        // Sin período elegido se exporta todo lo filtrado (el nombre del archivo lleva la fecha de hoy).
        $period = $periodId !== '' ? AcademicPeriod::query()->find($periodId) : null;
        $name = 'auditoria-'.($period instanceof AcademicPeriod ? Str::slug($period->codigo) : InstitutionDate::today()->toDateString()).'.xlsx';
        $book = $workbook->build($rows, $period instanceof AcademicPeriod ? $period->codigo : null);

        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->setPreCalculateFormulas(false)->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return Builder<AuditEvent> */
    private function filtered(ViewAuditEventsRequest $request): Builder
    {
        $action = $request->string('action')->toString();
        $result = $request->string('result')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $periodId = $request->string('period')->toString();
        $roleCode = $request->string('role')->toString();
        $careerId = $request->string('career')->toString();
        $query = AuditEvent::query()->with([
            'actor:id,nombre',
            'roleAssignment',
            'roleAssignment.career:id,nombre,codigo_carrera',
        ]);
        if ($action !== '') {
            $query->where('accion', $action);
        } elseif (! $request->boolean('technical')) {
            // Por defecto, solo lo que cambia datos: sin inicios de sesión, guardados automáticos ni descargas.
            $query->whereNotIn('accion', AuditTargets::TECHNICAL_ACTIONS);
        }
        if ($result !== '') {
            $query->where('resultado', $result);
        }
        $period = $periodId !== '' ? AcademicPeriod::query()->find($periodId) : null;
        if ($periodId !== '' && ! $period instanceof AcademicPeriod) {
            $query->whereRaw('1 = 0');
        }
        if ($period instanceof AcademicPeriod) {
            $query->whereBetween('ocurrido_en', [
                CarbonImmutable::createFromFormat('Y-m-d', $period->fecha_inicio->toDateString(), InstitutionDate::timezone())->startOfDay()->utc(),
                CarbonImmutable::createFromFormat('Y-m-d', $period->fecha_fin->toDateString(), InstitutionDate::timezone())->endOfDay()->utc(),
            ]);
        }
        $role = RoleCode::tryFrom($roleCode);
        if ($role instanceof RoleCode) {
            $query->where(fn ($builder) => $builder
                ->where('actor_rol', $role->label())
                ->orWhereHas('roleAssignment', fn ($assignment) => $assignment->where('rol', $role->value)));
        }
        $career = $careerId !== '' ? Career::query()->find($careerId) : null;
        if ($careerId !== '' && ! $career instanceof Career) {
            $query->whereRaw('1 = 0');
        }
        if ($career instanceof Career) {
            $query->where(fn ($builder) => $builder
                ->where('actor_carrera', $career->label())
                ->orWhereHas('roleAssignment', fn ($assignment) => $assignment->where('carrera_id', $career->id)));
        }
        if ($from !== '') {
            $query->where('ocurrido_en', '>=', CarbonImmutable::createFromFormat('Y-m-d', $from, InstitutionDate::timezone())
                ->startOfDay()->utc());
        }
        if ($to !== '') {
            $query->where('ocurrido_en', '<=', CarbonImmutable::createFromFormat('Y-m-d', $to, InstitutionDate::timezone())
                ->endOfDay()->utc());
        }

        return $query;
    }

    private function detailText(bool|float|int|string $value): string
    {
        return is_bool($value) ? ($value ? 'Sí' : 'No') : (string) $value;
    }

    /** @return list<array{value: string, label: string}> */
    private function actionOptions(): array
    {
        $options = [];
        foreach (AuditEvent::query()->distinct()->orderBy('accion')->pluck('accion') as $value) {
            if (is_string($value)) {
                $options[] = ['value' => $value, 'label' => $this->actionLabel($value)];
            }
        }

        return $options;
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'academico.campus.creacion' => 'Campus creado',
            'academico.campus.cambio_estado' => 'Estado de campus actualizado',
            'academico.campus.actualizacion' => 'Campus actualizado',
            'academico.carrera.creacion' => 'Carrera creada',
            'academico.carrera.cambio_estado' => 'Estado de carrera actualizado',
            'academico.carrera.desactivacion' => 'Carrera desactivada',
            'academico.carrera.reactivacion' => 'Carrera reactivada',
            'academico.carrera.actualizacion' => 'Carrera actualizada',
            'academico.documento_apoyo.subida' => 'Documento de apoyo subido',
            'academico.documento_apoyo.eliminacion' => 'Documento de apoyo eliminado',
            'academico.facultad.creacion' => 'Facultad creada',
            'academico.facultad.cambio_estado' => 'Estado de facultad actualizado',
            'academico.facultad.actualizacion' => 'Facultad actualizada',
            'academico.modalidad.creacion' => 'Modalidad creada',
            'academico.modalidad.cambio_estado' => 'Estado de modalidad actualizado',
            'academico.modalidad.actualizacion' => 'Modalidad actualizada',
            'academico.periodo.creacion' => 'Periodo académico creado',
            'academico.periodo.cambio_estado' => 'Estado de periodo académico actualizado',
            'academico.periodo.actualizacion' => 'Periodo académico actualizado',
            'academico.malla.actualizacion' => 'Malla actualizada',
            'academico.malla.eliminacion' => 'Malla eliminada',
            'academico.malla.configuracion_actualizada' => 'Configuración de malla actualizada',
            'academico.carrera.plan_curricular_actualizado' => 'Plan curricular actualizado',
            'academico.campo_malla.creacion' => 'Campo de malla agregado',
            'academico.campo_malla.eliminacion' => 'Campo de malla retirado',
            'academico.asignatura.creacion' => 'Materia creada',
            'academico.asignatura.actualizacion' => 'Materia actualizada',
            'academico.asignatura.eliminacion' => 'Materia retirada',
            'academico.asignatura.posicion_actualizada' => 'Materia reubicada en la malla',
            'academico.requisito_asignatura.creacion' => 'Relación académica agregada',
            'academico.requisito_asignatura.eliminacion' => 'Relación académica eliminada',
            'academico.programacion_asignatura.creacion' => 'Materia programada',
            'academico.programacion_asignatura.actualizacion' => 'Programación de asignatura actualizada',
            'academico.programacion_asignatura.eliminacion' => 'Materia programada eliminada',
            'academico.oferta.creacion' => 'Materia programada',
            'academico.oferta.actualizacion' => 'Programación de asignatura actualizada',
            'academico.oferta.eliminacion' => 'Materia programada eliminada',
            'academico.paralelo.creacion' => 'Paralelo creado',
            'academico.periodo_arrastrado' => 'Paralelos y docentes copiados del período anterior',
            'academico.paralelo.actualizacion' => 'Paralelo actualizado',
            'academico.asignacion_docente.creacion' => 'Asignación docente creada',
            'academico.asignacion_docente.actualizacion' => 'Asignación docente actualizada',
            'convocatoria.creada' => 'Convocatoria creada',
            'convocatoria.abierta' => 'Convocatoria abierta',
            'convocatoria.plazo_extendido' => 'Plazo de convocatoria extendido',
            'documento.descargado' => 'Documento descargado',
            'documento.exportacion_completada' => 'Exportación completada',
            'documento.exportacion_fallida' => 'Exportación fallida',
            'documento.exportacion_solicitada' => 'Exportación solicitada',
            'silabo.firmado' => 'Sílabo firmado electrónicamente',
            'trabajo.reintento_solicitado' => 'Reintento solicitado',
            'fuente.creada' => 'Fuente académica creada',
            'fuente.actualizada' => 'Fuente académica actualizada',
            'fuente.contenido_actualizado' => 'Contenido de fuente actualizado',
            'silabo.aprobado' => 'Sílabo aprobado',
            'biblioteca.verificado' => 'Biblioteca aprobó la bibliografía',
            'silabo.revisado_coordinacion' => 'Coordinación revisó y aprobó el sílabo',
            'silabo.correccion_solicitada' => 'Corrección solicitada',
            'silabo.eliminado' => 'Sílabo eliminado',
            'silabo.reiniciado' => 'Sílabo reiniciado',
            'silabo.reenviado' => 'Sílabo reenviado',
            'silabo.enviado' => 'Sílabo enviado',
            'silabo.observacion_agregada' => 'Observación registrada',
            'silabo.observacion_respondida' => 'Observación respondida',
            'silabo.observacion_verificada' => 'Observación verificada',
            'silabo.borrador_iniciado' => 'Borrador iniciado',
            'silabo.campo_guardado' => 'Campo guardado',
            'silabo.docente_transferido' => 'Docente transferido',
            'silabo.validado' => 'Borrador validado',
            'plantilla.creada' => 'Plantilla creada',
            'plantilla.formateada' => 'Formato oficial aplicado a la plantilla',
            'plantilla.version_publicada' => 'Plantilla publicada',
            'plantilla.version_clonada' => 'Versión de plantilla clonada',
            'plantilla.propuesta_ia_aplicada' => 'Propuesta del asistente aplicada en el editor',
            'plantilla.propuesta_ia_descartada' => 'Propuesta del asistente descartada',
            'ia.plantilla_solicitada' => 'Pedido al asistente de la plantilla',
            'usuario.creado' => 'Usuario creado',
            'usuario.rol_asignado' => 'Rol asignado',
            'usuario.rol_retirado' => 'Rol retirado',
            'usuario.activado' => 'Cuenta activada',
            'usuario.desactivado' => 'Cuenta desactivada',
            'usuario.perfil_actualizado' => 'Perfil actualizado',
            'usuario.contrasena_actualizada' => 'Contraseña actualizada',
            'usuario.invitacion_activacion_enviada' => 'Invitación de activación enviada',
            'ayuda.guardada' => 'Ayuda de pantalla guardada',
            'ayuda.eliminada' => 'Ayuda de pantalla eliminada',
            'rol_activo.seleccionado' => 'Rol activo seleccionado',
            'ia.analisis_solicitado' => 'Análisis de IA solicitado',
            'ia.analisis_completado' => 'Análisis de IA completado',
            'ia.analisis_fallido' => 'Análisis de IA fallido',
            'academico.coordinacion.reemplazada' => 'Coordinación reemplazada',
            'academico.ficha_asignatura.guardada' => 'Proyecto de carrera de la materia guardado',
            'academico.paralelo.eliminacion' => 'Paralelo eliminado',
            'convocatoria.iniciada' => 'Convocatoria de carrera preparada',
            'plantilla.bloque_eliminado' => 'Bloque de la plantilla eliminado',
            'plantilla.bloques_reordenados' => 'Bloques de la plantilla reordenados',
            'plantilla.importada' => 'Plantilla importada',
            'plantilla.respuestas_protegidas_activadas' => 'Respuestas de la plantilla protegidas',
            'plantilla.seccion_actualizada' => 'Sección de la plantilla actualizada',
            'plantilla.seccion_creada' => 'Sección de la plantilla creada',
            'plantilla.seccion_eliminada' => 'Sección de la plantilla eliminada',
            'plantilla.secciones_reordenadas' => 'Secciones de la plantilla reordenadas',
            'plantilla.vaciada' => 'Plantilla vaciada',
            'proceso.plantilla_rehecho' => 'Sílabos rehechos con la plantilla nueva',
            'proceso_silabos.creado' => 'Convocatoria institucional creada',
            'proceso_silabos.abierto' => 'Convocatoria institucional abierta',
            'proceso_silabos.cerrado' => 'Convocatoria institucional cerrada',
            'proceso_silabos.eliminado' => 'Convocatoria institucional eliminada',
            'proceso_silabos.plazo_extendido' => 'Plazo de la convocatoria extendido',
            'proceso_silabos.reabierto' => 'Convocatoria institucional reabierta',
            'rol_activo.cerrado' => 'Rol activo cerrado',
            'sesion.iniciada' => 'Sesión iniciada',
            'sesion.cerrada' => 'Sesión cerrada',
            'silabo.contenido_copiado' => 'Contenido del sílabo copiado',
            'usuario.docente_incorporado' => 'Docente incorporado a la carrera',
            'usuario.docente_retirado' => 'Docente retirado de la carrera',
            'usuario.eliminado' => 'Usuario eliminado',
            'usuario.formacion_actualizada' => 'Formación del docente actualizada',
            default => 'Acción registrada',
        };
    }

    private function resourceLabel(string $resource): string
    {
        return match ($resource) {
            'fuente_academica' => 'Fuente académica',
            'aprobacion' => 'Aprobación',
            'firma_silabo' => 'Firma electrónica',
            'envio_biblioteca' => 'Envío a biblioteca',
            'revision_coordinacion' => 'Revisión de coordinación',
            'campus' => 'Campus',
            'convocatoria' => 'Convocatoria',
            'carrera' => 'Carrera',
            'solicitud_correccion' => 'Solicitud de corrección',
            'artefacto_exportacion' => 'Exportación',
            'documento_apoyo' => 'Documento de apoyo',
            'facultad' => 'Facultad',
            'ejecucion_trabajo' => 'Trabajo asíncrono',
            'malla' => 'Malla',
            'campo_malla' => 'Campo de malla',
            'asignatura' => 'Materia',
            'requisito' => 'Relación académica',
            'programacion_asignatura' => 'Programación de asignatura',
            'oferta' => 'Programación de asignatura',
            'paralelo' => 'Paralelo',
            'asignacion_docente' => 'Asignación docente',
            'asignacion_coordinador' => 'Asignación de coordinación',
            'periodo' => 'Periodo académico',
            'observacion_revision' => 'Observación',
            'silabo' => 'Sílabo',
            'revision_silabo' => 'Revisión de sílabo',
            'version_plantilla' => 'Versión de plantilla',
            'plantilla_silabo' => 'Plantilla de sílabo',
            'propuesta_ia_plantilla' => 'Propuesta del asistente',
            'mensaje_ia_plantilla' => 'Asistente de la plantilla',
            'ejecucion_ia' => 'Análisis de IA',
            'asignacion_rol' => 'Asignación de rol',
            'usuario' => 'Usuario',
            'ayuda_pantalla' => 'Ayuda de pantalla',
            default => 'Recurso del sistema',
        };
    }

    private function actorName(AuditEvent $event): string
    {
        $actor = $event->getRelation('actor');
        if ($event->actor_nombre !== null) {
            return $event->actor_nombre;
        }
        if ($actor instanceof User) {
            return $actor->nombre;
        }

        return $event->actor_usuario_id === null ? 'Sistema' : 'Cuenta eliminada';
    }

    /**
     * @param  array<string, mixed>|null  $activeRole
     * @return list<array{label: string, value: bool|float|int|string}>
     */
    private function safeDetails(?array $activeRole): array
    {
        $labels = [
            'active' => 'Activo',
            'after_code' => 'Código nuevo',
            'after_shift' => 'Jornada nueva',
            'before_shift' => 'Jornada anterior',
            'from_state' => 'Estado anterior',
            'to_state' => 'Estado nuevo',
            'after_ends_on' => 'Fecha de fin nueva',
            'after_faculty' => 'Facultad nueva',
            'after_name' => 'Nombre nuevo',
            'after_starts_on' => 'Fecha de inicio nueva',
            'after_version_number' => 'Número de versión nuevo',
            'after_cycle' => 'Ciclo nuevo',
            'after_cycle_count' => 'Cantidad de ciclos nueva',
            'after_credits' => 'Créditos nuevos',
            'after_total_hours' => 'Horas totales nuevas',
            'before_code' => 'Código anterior',
            'before_ends_on' => 'Fecha de fin anterior',
            'before_faculty' => 'Facultad anterior',
            'before_name' => 'Nombre anterior',
            'before_starts_on' => 'Fecha de inicio anterior',
            'before_version_number' => 'Número de versión anterior',
            'before_cycle' => 'Ciclo anterior',
            'before_cycle_count' => 'Cantidad de ciclos anterior',
            'before_credits' => 'Créditos anteriores',
            'before_total_hours' => 'Horas totales anteriores',
            'blocking_errors' => 'Errores bloqueantes',
            'changed_fields' => 'Campos modificados',
            'decision' => 'Decisión',
            'field_key' => 'Campo',
            'key' => 'Clave del campo',
            'format' => 'Formato',
            'generated_count' => 'Expedientes generados',
            'initial_role' => 'Rol inicial',
            'job_type' => 'Tipo de trabajo',
            'lock_version' => 'Versión de edición',
            'observation_count' => 'Observaciones',
            'previous_attempts' => 'Intentos anteriores',
            'previous_error_code' => 'Categoría de fallo previa',
            'renderer_version' => 'Versión de formato',
            'retired_users' => 'Usuarios retirados',
            'revision_number' => 'Número de revisión',
            'role' => 'Rol',
            'rule_version' => 'Versión de reglas',
            'source_count' => 'Fuentes vinculadas',
            'type' => 'Tipo de relación',
        ];
        // Un cambio se lee como «Jornada: nocturna → matutina»; si el valor no cambió, no se muestra.
        $pairs = [
            'code' => 'Código', 'shift' => 'Jornada', 'name' => 'Nombre', 'faculty' => 'Facultad', 'starts_on' => 'Fecha de inicio',
            'ends_on' => 'Fecha de fin', 'version_number' => 'Número de versión', 'cycle' => 'Ciclo', 'cycle_count' => 'Cantidad de ciclos',
            'credits' => 'Créditos', 'total_hours' => 'Horas totales', 'state' => 'Estado',
        ];
        $scalar = fn (mixed $value): bool => is_bool($value) || is_float($value) || is_int($value) || is_string($value);
        $metadata = $activeRole ?? [];
        $paired = [];
        $details = [];
        foreach ($pairs as $field => $label) {
            [$beforeKey, $afterKey] = $field === 'state' ? ['from_state', 'to_state'] : ["before_{$field}", "after_{$field}"];
            if (! array_key_exists($beforeKey, $metadata) && ! array_key_exists($afterKey, $metadata)) {
                continue;
            }
            $paired[] = $beforeKey;
            $paired[] = $afterKey;
            $before = $metadata[$beforeKey] ?? null;
            $after = $metadata[$afterKey] ?? null;
            if ($before != $after && ($before === null || $scalar($before)) && ($after === null || $scalar($after))) {
                $text = fn (mixed $value): string => $value === null || $value === '' ? '—' : (is_bool($value) ? ($value ? 'Sí' : 'No') : self::VALUE_LABELS[(string) $value] ?? (string) $value);
                $details[] = ['label' => $label, 'value' => "{$text($before)} → {$text($after)}"];
            }
        }
        foreach ($metadata as $key => $value) {
            if (! in_array($key, $paired, true) && isset($labels[$key]) && $scalar($value)) {
                /** @var bool|float|int|string $value */
                $details[] = ['label' => $labels[$key], 'value' => is_string($value) ? (self::VALUE_LABELS[$value] ?? $value) : $value];
            }
        }

        return $details;
    }
}
