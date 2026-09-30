<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$partesInglesas = [
    'created', 'updated', 'deleted', 'name', 'email', 'password', 'active', 'status',
    'type', 'queue', 'payload', 'attempts', 'progress', 'started', 'finished', 'locale',
    'snapshot', 'lock', 'token', 'key', 'value', 'owner', 'expiration', 'user', 'agent',
    'activity', 'connection', 'exception', 'failed', 'batch', 'migration', 'remember',
    'secret', 'recovery', 'must', 'change', 'resource', 'correlation', 'gateway',
    'renderer', 'message', 'jobs', 'job', 'cache', 'sessions',
];

$tablasDeFramework = ['sesiones', 'trabajos_fallidos', 'restablecimientos_contrasena', 'migraciones'];

it('I-28 no conserva identificadores en inglés en tablas ni columnas propias', function () use ($partesInglesas, $tablasDeFramework) {
    $columnas = DB::select(
        "SELECT table_name, column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
         ORDER BY table_name, ordinal_position",
    );

    $violaciones = [];
    foreach ($columnas as $columna) {
        foreach (explode('_', $columna->table_name) as $parte) {
            if (in_array($parte, $partesInglesas, true)) {
                $violaciones[] = "tabla {$columna->table_name}";
            }
        }
        if (in_array($columna->table_name, $tablasDeFramework, true)) {
            continue;
        }
        foreach (explode('_', $columna->column_name) as $parte) {
            if (in_array($parte, $partesInglesas, true)) {
                $violaciones[] = "{$columna->table_name}.{$columna->column_name}";
            }
        }
    }

    expect(array_values(array_unique($violaciones)))->toBe([]);
});

it('I-28 no conserva valores en inglés en los CHECK de estados', function () {
    $valoresIngleses = [
        'pending', 'running', 'completed', 'failed', 'draft', 'published', 'active',
        'inactive', 'historical', 'open', 'closed', 'preparation', 'not_started',
        'in_review', 'correction_requested', 'approved', 'submit', 'approve', 'reopen',
        'resubmit', 'request_correction', 'verified', 'responded', 'quarantined',
        'processing', 'processed', 'inconclusive', 'accepted', 'ignored', 'not_useful',
        'applied', 'clarity', 'consistency', 'warning', 'success', 'denied', 'group',
        'fields', 'narrative', 'workflow', 'short_text', 'long_text', 'single_select',
        'multi_select', 'boolean', 'calculation', 'master_reference', 'text', 'number',
        'integer', 'prerequisite', 'per_offering', 'per_parallel', 'start', 'review',
        'correction',
    ];

    $checks = DB::select(
        "SELECT conrelid::regclass::text AS tabla, conname, pg_get_constraintdef(oid) AS definicion
         FROM pg_constraint
         WHERE contype = 'c' AND connamespace = 'public'::regnamespace",
    );

    $violaciones = [];
    foreach ($checks as $check) {
        foreach ($valoresIngleses as $valor) {
            if (str_contains($check->definicion, "'{$valor}'")) {
                $violaciones[] = "{$check->tabla}.{$check->conname} conserva '{$valor}'";
            }
        }
    }

    expect($violaciones)->toBe([]);
});

it('I-52 no conserva marcas genéricas de auditoría en tablas de dominio', function () use ($tablasDeFramework) {
    $columnas = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->whereNotIn('table_name', $tablasDeFramework)
        ->whereIn('column_name', ['creado_en', 'actualizado_en', 'registrado_en'])
        ->get(['table_name', 'column_name'])
        ->map(fn ($column) => "{$column->table_name}.{$column->column_name}")
        ->all();

    expect($columnas)->toBe([]);
});

it('I-78 usa nombres explícitos para todos los atributos genéricos de dominio', function () use ($tablasDeFramework) {
    $nombresGenericos = [
        'codigo', 'nombre', 'descripcion', 'activo', 'estado', 'tipo', 'titulo',
        'contenido', 'valor', 'origen', 'clave', 'etiqueta', 'ayuda', 'reglas',
        'opciones', 'posicion', 'modalidad', 'fecha_inicio', 'fecha_fin',
        'semanas_lectivas', 'numero_ciclos', 'creditos', 'horas_totales',
        'orden_en_ciclo', 'unidad_organizacion_curricular', 'mapeo_documento',
        'configuracion', 'datos', 'advertencias', 'extracto', 'decision',
        'fotografia', 'justificacion', 'metadatos', 'mensaje',
    ];

    $columnas = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->whereNotIn('table_name', $tablasDeFramework)
        ->whereIn('column_name', $nombresGenericos)
        ->get(['table_name', 'column_name'])
        ->map(fn ($column) => "{$column->table_name}.{$column->column_name}")
        ->all();

    expect($columnas)->toBe([])
        ->and(Schema::hasColumn('usuarios', 'nombre_usuario'))->toBeTrue()
        ->and(Schema::hasColumn('usuarios', 'usuario_activo'))->toBeTrue();
});

it('I-79 nombra la responsabilidad operativa como asignación a un paralelo', function () {
    expect(Schema::hasTable('asignaciones_paralelo'))->toBeTrue()
        ->and(Schema::hasTable('docentes_paralelo'))->toBeFalse();

    $restricciones = collect(DB::select(
        "SELECT conname
         FROM pg_constraint
         WHERE conrelid = 'asignaciones_paralelo'::regclass",
    ))->pluck('conname');

    expect($restricciones)
        ->toContain('asignaciones_paralelo_pkey')
        ->toContain('asignaciones_paralelo_paralelo_id_foreign')
        ->toContain('asignaciones_paralelo_asignacion_rol_id_foreign');
});

it('I-80 persiste los tres roles fijos en la asignación y no en un catálogo', function () {
    expect(Schema::hasTable('roles'))->toBeFalse()
        ->and(Schema::hasColumn('asignaciones_rol', 'rol'))->toBeTrue()
        ->and(Schema::hasColumn('asignaciones_rol', 'rol_id'))->toBeFalse();

    $restricciones = collect(DB::select(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'asignaciones_rol'::regclass",
    ))->pluck('conname');

    expect($restricciones)
        ->toContain('asignaciones_rol_rol_valido_check')
        ->toContain('asignaciones_rol_alcance_valido_check');
});

it('I-81 referencia el logo de facultad como archivo', function () {
    expect(Schema::hasColumn('facultades', 'logo_archivo_id'))->toBeTrue()
        ->and(Schema::hasColumn('facultades', 'ruta_logo_facultad'))->toBeFalse();

    $restricciones = collect(DB::select(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'facultades'::regclass",
    ))->pluck('conname');

    expect($restricciones)->toContain('facultades_logo_archivo_id_foreign');
});

it('I-175 usa nombres claros para archivos, exportaciones, firmas y documentos de importación', function () {
    foreach (['objetos_almacenados', 'artefactos_exportacion', 'fuentes_academicas'] as $tabla) {
        expect(Schema::hasTable($tabla))->toBeFalse();
    }

    expect(Schema::hasColumns('archivos', ['nombre_archivo', 'tipo_archivo', 'categoria', 'subido_por', 'ruta_archivo', 'huella_archivo']))->toBeTrue()
        ->and(Schema::hasColumns('exportaciones_silabo', ['archivo_word_id', 'archivo_pdf_id', 'version_generador', 'estado_exportacion', 'trabajo_id', 'clave_solicitud']))->toBeTrue()
        ->and(Schema::hasColumns('firmas_documento', [
            'exportacion_silabo_id', 'firmado_como', 'archivo_firmado_id', 'nombre_en_certificado',
            'numero_certificado', 'entidad_emisora', 'certificado_valido_hasta', 'huella_archivo',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('documentos_importacion', [
            'nombre_documento', 'documento_activo', 'archivo_pdf_id', 'estado_lectura', 'lectura_actualizada_en', 'contenido_documento',
        ]))->toBeTrue();

    $restricciones = collect(DB::select(
        "SELECT conname FROM pg_constraint WHERE conrelid IN ('archivos'::regclass, 'exportaciones_silabo'::regclass, 'firmas_documento'::regclass, 'documentos_importacion'::regclass)",
    ))->pluck('conname');

    expect($restricciones)->toContain('archivos_pkey', 'exportaciones_silabo_trabajo_id_foreign', 'firmas_documento_exportacion_silabo_id_foreign', 'documentos_importacion_archivo_pdf_id_foreign')
        ->and($restricciones->filter(fn (string $nombre): bool => str_contains($nombre, 'objeto') || str_contains($nombre, 'artefacto') || str_contains($nombre, 'fuente')))->toBeEmpty();
});

it('I-107 guarda ciclos en la carrera y retira códigos de facultad y malla', function () {
    expect(Schema::hasTable('mallas'))->toBeFalse()
        ->and(Schema::hasColumn('facultades', 'codigo_facultad'))->toBeFalse()
        ->and(Schema::hasColumn('carreras', 'codigo_malla'))->toBeFalse()
        ->and(Schema::hasColumn('carreras', 'codigo_carrera'))->toBeTrue()
        ->and(Schema::hasColumn('carreras', 'cantidad_ciclos_malla'))->toBeTrue()
        ->and(Schema::hasColumn('asignaturas', 'carrera_id'))->toBeTrue()
        ->and(Schema::hasColumn('asignaturas', 'malla_id'))->toBeFalse()
        ->and(Schema::hasColumn('silabos', 'carrera_id'))->toBeFalse()
        ->and(Schema::hasColumn('silabos', 'malla_id'))->toBeFalse();

    $careerCode = collect(DB::select(
        "SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'carreras' AND column_name = 'codigo_carrera'",
    ))->first();
    expect($careerCode?->is_nullable)->toBe('NO');

    $restricciones = collect(DB::select(
        "SELECT conname
         FROM pg_constraint
         WHERE conrelid IN ('carreras'::regclass, 'asignaturas'::regclass, 'silabos'::regclass)",
    ))->pluck('conname');

    expect($restricciones)
        ->toContain('carreras_cantidad_ciclos_malla_check')
        ->toContain('asignaturas_carrera_id_foreign')
        ->toContain('asignaturas_carrera_id_codigo_asignatura_unique')
        ->toContain('silabos_paralelo_id_foreign')
        ->toContain('silabos_convocatoria_id_paralelo_id_unique')
        ->not->toContain('silabos_carrera_id_foreign');
});

it('I-62 persiste la programación de asignaturas con nombres e invariante propios', function () {
    expect(Schema::hasTable('programaciones_asignatura'))->toBeTrue()
        ->and(Schema::hasTable('ofertas_academicas'))->toBeFalse()
        ->and(Schema::hasColumn('paralelos', 'programacion_asignatura_id'))->toBeTrue()
        ->and(Schema::hasColumn('paralelos', 'oferta_academica_id'))->toBeFalse()
        ->and(Schema::hasTable('alcances_silabo'))->toBeFalse()
        ->and(Schema::hasColumn('silabos', 'paralelo_id'))->toBeTrue()
        ->and(Schema::hasColumn('silabos', 'carrera_id'))->toBeFalse();

    $restricciones = collect(DB::select(
        "SELECT conname
         FROM pg_constraint
         WHERE conrelid = 'programaciones_asignatura'::regclass",
    ))->pluck('conname');

    expect($restricciones)
        ->toContain('programaciones_asignatura_periodo_materia_unica')
        ->toContain('programaciones_asignatura_periodo_academico_id_foreign')
        ->toContain('programaciones_asignatura_asignatura_id_foreign')
        ->toContain('programaciones_asignatura_id_not_null')
        ->not->toContain('ofertas_academicas_id_not_null');
});

it('I-176 nombra con claridad trabajos, notificaciones, auditoría y mensajes de IA', function () {
    expect(Schema::hasTable('trabajos_en_cola'))->toBeTrue()
        ->and(Schema::hasTable('ejecuciones_trabajo'))->toBeFalse()
        ->and(Schema::hasTable('notificaciones'))->toBeTrue()
        ->and(Schema::hasTable('notificaciones_internas'))->toBeFalse()
        ->and(Schema::hasTable('mensajes_ia'))->toBeTrue()
        ->and(Schema::hasTable('historial_ia'))->toBeFalse()
        ->and(Schema::hasColumns('trabajos_en_cola', ['tipo_trabajo', 'estado_trabajo', 'resultado_trabajo', 'datos_entrada', 'clave_solicitud']))->toBeTrue()
        ->and(Schema::hasColumns('notificaciones', ['tipo_notificacion', 'titulo_notificacion', 'mensaje_notificacion', 'clave_unica']))->toBeTrue()
        ->and(Schema::hasColumns('eventos_auditoria', ['usuario_id', 'accion', 'resultado', 'detalles']))->toBeTrue()
        ->and(Schema::hasColumns('mensajes_ia', ['conversacion_id', 'contenido_mensaje']))->toBeTrue();

    $restricciones = collect(DB::select(
        "SELECT conname FROM pg_constraint
         WHERE conrelid IN ('trabajos_en_cola'::regclass, 'notificaciones'::regclass, 'eventos_auditoria'::regclass, 'mensajes_ia'::regclass)",
    ))->pluck('conname');

    expect($restricciones)
        ->toContain('trabajos_en_cola_pkey')
        ->toContain('trabajos_en_cola_clave_solicitud_unique')
        ->toContain('notificaciones_usuario_id_foreign')
        ->toContain('eventos_auditoria_usuario_id_foreign')
        ->toContain('mensajes_ia_contenido_mensaje_check')
        ->and($restricciones->filter(fn (string $nombre): bool => str_starts_with($nombre, 'ejecuciones_trabajo_')
            || str_starts_with($nombre, 'notificaciones_internas_')
            || str_starts_with($nombre, 'historial_ia_'))->all())->toBe([]);
});
