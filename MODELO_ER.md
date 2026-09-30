# Modelo entidad-relación (notación Chen)

34 entidades y 62 relaciones, generado de la base actual (sin las tablas internas de Laravel).

- Rectángulo = entidad · rombo = relación (verbo) · óvalo = atributo (clave subrayada; `(FK)` = llave foránea) · en las líneas, `1` y `N` (muchos).

```mermaid
flowchart LR
    aprobaciones["APROBACIONES"]
    aprobaciones_id(["<u>id</u>"]) --- aprobaciones
    aprobaciones_clave_solicitud(["clave_solicitud"]) --- aprobaciones
    aprobaciones_hash_contenido(["hash_contenido"]) --- aprobaciones
    aprobaciones_aprobado_en(["aprobado_en"]) --- aprobaciones
    archivos["ARCHIVOS"]
    archivos_id(["<u>id</u>"]) --- archivos
    archivos_disco(["disco"]) --- archivos
    archivos_ruta_archivo(["ruta_archivo"]) --- archivos
    archivos_nombre_archivo(["nombre_archivo"]) --- archivos
    archivos_tipo_archivo(["tipo_archivo"]) --- archivos
    archivos_tamano_bytes(["tamano_bytes"]) --- archivos
    archivos_hash_archivo(["hash_archivo"]) --- archivos
    archivos_categoria(["categoria"]) --- archivos
    archivos_almacenado_en(["almacenado_en"]) --- archivos
    asignaciones_paralelo["ASIGNACIONES PARALELO"]
    asignaciones_paralelo_id(["<u>id</u>"]) --- asignaciones_paralelo
    asignaciones_paralelo_asignacion_paralelo_activa(["asignacion_paralelo_activa"]) --- asignaciones_paralelo
    asignaciones_paralelo_asignado_en(["asignado_en"]) --- asignaciones_paralelo
    asignaciones_rol["ASIGNACIONES ROL"]
    asignaciones_rol_id(["<u>id</u>"]) --- asignaciones_rol
    asignaciones_rol_asignacion_rol_activa(["asignacion_rol_activa"]) --- asignaciones_rol
    asignaciones_rol_asignado_en(["asignado_en"]) --- asignaciones_rol
    asignaciones_rol_rol(["rol"]) --- asignaciones_rol
    asignaturas["ASIGNATURAS"]
    asignaturas_id(["<u>id</u>"]) --- asignaturas
    asignaturas_codigo_asignatura(["codigo_asignatura"]) --- asignaturas
    asignaturas_nombre_asignatura(["nombre_asignatura"]) --- asignaturas
    asignaturas_ciclo_asignatura(["ciclo_asignatura"]) --- asignaturas
    asignaturas_creditos_asignatura(["creditos_asignatura"]) --- asignaturas
    asignaturas_asignatura_activa(["asignatura_activa"]) --- asignaturas
    asignaturas_horas_docencia(["horas_docencia"]) --- asignaturas
    asignaturas_horas_practicas(["horas_practicas"]) --- asignaturas
    asignaturas_horas_autonomas(["horas_autonomas"]) --- asignaturas
    asignaturas_orden_asignatura_en_ciclo(["orden_asignatura_en_ciclo"]) --- asignaturas
    asignaturas_unidad_curricular_asignatura(["unidad_curricular_asignatura"]) --- asignaturas
    asignaturas_modalidad_asignatura(["modalidad_asignatura"]) --- asignaturas
    asignaturas_total_horas_asignatura(["total_horas_asignatura"]) --- asignaturas
    asignaturas_ofertadas["ASIGNATURAS OFERTADAS"]
    asignaturas_ofertadas_id(["<u>id</u>"]) --- asignaturas_ofertadas
    asignaturas_ofertadas_asignatura_ofertada_activa(["asignatura_ofertada_activa"]) --- asignaturas_ofertadas
    campos_plantilla["CAMPOS PLANTILLA"]
    campos_plantilla_id(["<u>id</u>"]) --- campos_plantilla
    campos_plantilla_clave_campo_plantilla(["clave_campo_plantilla"]) --- campos_plantilla
    campos_plantilla_titulo_campo_plantilla(["titulo_campo_plantilla"]) --- campos_plantilla
    campos_plantilla_posicion_campo_plantilla(["posicion_campo_plantilla"]) --- campos_plantilla
    campos_plantilla_configuracion_campo_plantilla(["configuracion_campo_plantilla"]) --- campos_plantilla
    carreras["CARRERAS"]
    carreras_id(["<u>id</u>"]) --- carreras
    carreras_codigo_carrera(["codigo_carrera"]) --- carreras
    carreras_nombre_carrera(["nombre_carrera"]) --- carreras
    carreras_carrera_activa(["carrera_activa"]) --- carreras
    carreras_modalidad_carrera(["modalidad_carrera"]) --- carreras
    carreras_numero_ciclos(["numero_ciclos"]) --- carreras
    convocatorias_carreras["CONVOCATORIAS CARRERAS"]
    convocatorias_carreras_id(["<u>id</u>"]) --- convocatorias_carreras
    convocatorias_carreras_estado_convocatoria_carrera(["estado_convocatoria_carrera"]) --- convocatorias_carreras
    convocatorias_universidad["CONVOCATORIAS UNIVERSIDAD"]
    convocatorias_universidad_id(["<u>id</u>"]) --- convocatorias_universidad
    convocatorias_universidad_fecha_inicio(["fecha_inicio"]) --- convocatorias_universidad
    convocatorias_universidad_fecha_entrega(["fecha_entrega"]) --- convocatorias_universidad
    convocatorias_universidad_estado_convocatoria_universidad(["estado_convocatoria_universidad"]) --- convocatorias_universidad
    definiciones_campo["DEFINICIONES CAMPO"]
    definiciones_campo_id(["<u>id</u>"]) --- definiciones_campo
    definiciones_campo_clave_definicion_campo(["clave_definicion_campo"]) --- definiciones_campo
    definiciones_campo_etiqueta_definicion_campo(["etiqueta_definicion_campo"]) --- definiciones_campo
    definiciones_campo_ayuda_definicion_campo(["ayuda_definicion_campo"]) --- definiciones_campo
    definiciones_campo_tipo_definicion_campo(["tipo_definicion_campo"]) --- definiciones_campo
    definiciones_campo_heredado_definicion_campo(["heredado_definicion_campo"]) --- definiciones_campo
    definiciones_campo_origen_dato_definicion_campo(["origen_dato_definicion_campo"]) --- definiciones_campo
    definiciones_campo_editable_docente_definicion_campo(["editable_docente_definicion_campo"]) --- definiciones_campo
    definiciones_campo_opciones_definicion_campo(["opciones_definicion_campo"]) --- definiciones_campo
    definiciones_campo_posicion_definicion_campo(["posicion_definicion_campo"]) --- definiciones_campo
    definiciones_campo_condicion_definicion_campo(["condicion_definicion_campo"]) --- definiciones_campo
    envios_biblioteca["ENVIOS BIBLIOTECA"]
    envios_biblioteca_id(["<u>id</u>"]) --- envios_biblioteca
    envios_biblioteca_enviado_en(["enviado_en"]) --- envios_biblioteca
    envios_biblioteca_verificado_en(["verificado_en"]) --- envios_biblioteca
    eventos_auditoria["EVENTOS AUDITORIA"]
    eventos_auditoria_id(["<u>id</u>"]) --- eventos_auditoria
    eventos_auditoria_accion(["accion"]) --- eventos_auditoria
    eventos_auditoria_tipo_recurso(["tipo_recurso"]) --- eventos_auditoria
    eventos_auditoria_recurso_id(["recurso_id"]) --- eventos_auditoria
    eventos_auditoria_resultado(["resultado"]) --- eventos_auditoria
    eventos_auditoria_detalles(["detalles"]) --- eventos_auditoria
    eventos_auditoria_ocurrido_en(["ocurrido_en"]) --- eventos_auditoria
    exportaciones_silabo["EXPORTACIONES SILABO"]
    exportaciones_silabo_id(["<u>id</u>"]) --- exportaciones_silabo
    exportaciones_silabo_clave_solicitud(["clave_solicitud"]) --- exportaciones_silabo
    exportaciones_silabo_estado_exportacion(["estado_exportacion"]) --- exportaciones_silabo
    exportaciones_silabo_solicitado_en(["solicitado_en"]) --- exportaciones_silabo
    exportaciones_silabo_completado_en(["completado_en"]) --- exportaciones_silabo
    facultades["FACULTADES"]
    facultades_id(["<u>id</u>"]) --- facultades
    facultades_nombre_facultad(["nombre_facultad"]) --- facultades
    facultades_facultad_activa(["facultad_activa"]) --- facultades
    facultades_campus_facultad(["campus_facultad"]) --- facultades
    firmas_documento["FIRMAS DOCUMENTO"]
    firmas_documento_id(["<u>id</u>"]) --- firmas_documento
    firmas_documento_firmado_como(["firmado_como"]) --- firmas_documento
    firmas_documento_nombre_en_certificado(["nombre_en_certificado"]) --- firmas_documento
    firmas_documento_numero_certificado(["numero_certificado"]) --- firmas_documento
    firmas_documento_entidad_emisora(["entidad_emisora"]) --- firmas_documento
    firmas_documento_certificado_valido_hasta(["certificado_valido_hasta"]) --- firmas_documento
    firmas_documento_hash_archivo(["hash_archivo"]) --- firmas_documento
    firmas_documento_firmado_en(["firmado_en"]) --- firmas_documento
    invitaciones_activacion["INVITACIONES ACTIVACION"]
    invitaciones_activacion_id(["<u>id</u>"]) --- invitaciones_activacion
    invitaciones_activacion_hash_activacion(["hash_activacion"]) --- invitaciones_activacion
    invitaciones_activacion_expira_en(["expira_en"]) --- invitaciones_activacion
    invitaciones_activacion_usada_en(["usada_en"]) --- invitaciones_activacion
    invitaciones_activacion_invalidada_en(["invalidada_en"]) --- invitaciones_activacion
    invitaciones_activacion_creada_en(["creada_en"]) --- invitaciones_activacion
    mensajes_ia["MENSAJES IA"]
    mensajes_ia_id(["<u>id</u>"]) --- mensajes_ia
    mensajes_ia_contenido_mensaje(["contenido_mensaje"]) --- mensajes_ia
    mensajes_ia_enviado_en(["enviado_en"]) --- mensajes_ia
    notificaciones["NOTIFICACIONES"]
    notificaciones_id(["<u>id</u>"]) --- notificaciones
    notificaciones_clave_unica(["clave_unica"]) --- notificaciones
    notificaciones_tipo_notificacion(["tipo_notificacion"]) --- notificaciones
    notificaciones_titulo_notificacion(["titulo_notificacion"]) --- notificaciones
    notificaciones_mensaje_notificacion(["mensaje_notificacion"]) --- notificaciones
    notificaciones_tipo_recurso(["tipo_recurso"]) --- notificaciones
    notificaciones_recurso_id(["recurso_id"]) --- notificaciones
    notificaciones_leido_en(["leido_en"]) --- notificaciones
    notificaciones_notificado_en(["notificado_en"]) --- notificaciones
    observaciones_revision["OBSERVACIONES REVISION"]
    observaciones_revision_id(["<u>id</u>"]) --- observaciones_revision
    observaciones_revision_clave_seccion(["clave_seccion"]) --- observaciones_revision
    observaciones_revision_clave_campo(["clave_campo"]) --- observaciones_revision
    observaciones_revision_contenido_observacion_revision(["contenido_observacion_revision"]) --- observaciones_revision
    observaciones_revision_estado_observacion_revision(["estado_observacion_revision"]) --- observaciones_revision
    observaciones_revision_observado_en(["observado_en"]) --- observaciones_revision
    paralelos["PARALELOS"]
    paralelos_id(["<u>id</u>"]) --- paralelos
    paralelos_codigo_paralelo(["codigo_paralelo"]) --- paralelos
    paralelos_jornada_paralelo(["jornada_paralelo"]) --- paralelos
    periodos_academicos["PERIODOS ACADEMICOS"]
    periodos_academicos_id(["<u>id</u>"]) --- periodos_academicos
    periodos_academicos_codigo_periodo_academico(["codigo_periodo_academico"]) --- periodos_academicos
    periodos_academicos_fecha_inicio_periodo(["fecha_inicio_periodo"]) --- periodos_academicos
    periodos_academicos_fecha_fin_periodo(["fecha_fin_periodo"]) --- periodos_academicos
    periodos_academicos_cantidad_semanas_lectivas(["cantidad_semanas_lectivas"]) --- periodos_academicos
    plantilla_silabo["PLANTILLA SILABO"]
    plantilla_silabo_id(["<u>id</u>"]) --- plantilla_silabo
    plantilla_silabo_mapeo_documento_plantilla(["mapeo_documento_plantilla"]) --- plantilla_silabo
    reaperturas["REAPERTURAS"]
    reaperturas_id(["<u>id</u>"]) --- reaperturas
    reaperturas_clave_solicitud(["clave_solicitud"]) --- reaperturas
    reaperturas_causa(["causa"]) --- reaperturas
    reaperturas_reabierto_en(["reabierto_en"]) --- reaperturas
    reaperturas_origen_reapertura(["origen_reapertura"]) --- reaperturas
    requisitos_asignatura["REQUISITOS ASIGNATURA"]
    requisitos_asignatura_id(["<u>id</u>"]) --- requisitos_asignatura
    requisitos_asignatura_tipo_requisito_asignatura(["tipo_requisito_asignatura"]) --- requisitos_asignatura
    resultados_validacion["RESULTADOS VALIDACION"]
    resultados_validacion_id(["<u>id</u>"]) --- resultados_validacion
    resultados_validacion_codigo_resultado_validacion(["codigo_resultado_validacion"]) --- resultados_validacion
    resultados_validacion_severidad_resultado_validacion(["severidad_resultado_validacion"]) --- resultados_validacion
    resultados_validacion_mensaje_resultado_validacion(["mensaje_resultado_validacion"]) --- resultados_validacion
    revisiones_silabo["REVISIONES SILABO"]
    revisiones_silabo_id(["<u>id</u>"]) --- revisiones_silabo
    revisiones_silabo_numero_revision(["numero_revision"]) --- revisiones_silabo
    revisiones_silabo_clave_solicitud(["clave_solicitud"]) --- revisiones_silabo
    revisiones_silabo_version_borrador(["version_borrador"]) --- revisiones_silabo
    revisiones_silabo_contenido_enviado(["contenido_enviado"]) --- revisiones_silabo
    revisiones_silabo_hash_contenido(["hash_contenido"]) --- revisiones_silabo
    revisiones_silabo_enviado_en(["enviado_en"]) --- revisiones_silabo
    secciones_plantilla["SECCIONES PLANTILLA"]
    secciones_plantilla_id(["<u>id</u>"]) --- secciones_plantilla
    secciones_plantilla_clave_seccion_plantilla(["clave_seccion_plantilla"]) --- secciones_plantilla
    secciones_plantilla_titulo_seccion_plantilla(["titulo_seccion_plantilla"]) --- secciones_plantilla
    secciones_plantilla_descripcion_seccion_plantilla(["descripcion_seccion_plantilla"]) --- secciones_plantilla
    secciones_plantilla_posicion_seccion_plantilla(["posicion_seccion_plantilla"]) --- secciones_plantilla
    silabos["SILABOS"]
    silabos_id(["<u>id</u>"]) --- silabos
    silabos_estado_silabo(["estado_silabo"]) --- silabos
    silabos_version_borrador(["version_borrador"]) --- silabos
    silabos_iniciado_en(["iniciado_en"]) --- silabos
    silabos_guardado_en(["guardado_en"]) --- silabos
    silabos_datos_academicos_silabo(["datos_academicos_silabo"]) --- silabos
    solicitudes_correccion["SOLICITUDES CORRECCION"]
    solicitudes_correccion_id(["<u>id</u>"]) --- solicitudes_correccion
    solicitudes_correccion_justificacion_solicitud_correccion(["justificacion_solicitud_correccion"]) --- solicitudes_correccion
    solicitudes_correccion_solicitado_en(["solicitado_en"]) --- solicitudes_correccion
    trabajos_en_cola["TRABAJOS EN COLA"]
    trabajos_en_cola_id(["<u>id</u>"]) --- trabajos_en_cola
    trabajos_en_cola_tipo_trabajo(["tipo_trabajo"]) --- trabajos_en_cola
    trabajos_en_cola_estado_trabajo(["estado_trabajo"]) --- trabajos_en_cola
    trabajos_en_cola_clave_solicitud(["clave_solicitud"]) --- trabajos_en_cola
    trabajos_en_cola_intentos(["intentos"]) --- trabajos_en_cola
    trabajos_en_cola_progreso(["progreso"]) --- trabajos_en_cola
    trabajos_en_cola_resultado_trabajo(["resultado_trabajo"]) --- trabajos_en_cola
    trabajos_en_cola_codigo_error(["codigo_error"]) --- trabajos_en_cola
    trabajos_en_cola_mensaje_error(["mensaje_error"]) --- trabajos_en_cola
    trabajos_en_cola_iniciado_en(["iniciado_en"]) --- trabajos_en_cola
    trabajos_en_cola_finalizado_en(["finalizado_en"]) --- trabajos_en_cola
    trabajos_en_cola_encolado_en(["encolado_en"]) --- trabajos_en_cola
    trabajos_en_cola_cola(["cola"]) --- trabajos_en_cola
    trabajos_en_cola_tipo_recurso(["tipo_recurso"]) --- trabajos_en_cola
    trabajos_en_cola_recurso_id(["recurso_id"]) --- trabajos_en_cola
    trabajos_en_cola_intentos_maximos(["intentos_maximos"]) --- trabajos_en_cola
    trabajos_en_cola_datos_entrada(["datos_entrada"]) --- trabajos_en_cola
    usuarios["USUARIOS"]
    usuarios_id(["<u>id</u>"]) --- usuarios
    usuarios_nombre_usuario(["nombre_usuario"]) --- usuarios
    usuarios_correo_electronico(["correo_electronico"]) --- usuarios
    usuarios_correo_verificado_en(["correo_verificado_en"]) --- usuarios
    usuarios_contrasena(["contrasena"]) --- usuarios
    usuarios_usuario_activo(["usuario_activo"]) --- usuarios
    usuarios_codigo_recordarme(["codigo_recordarme"]) --- usuarios
    usuarios_secreto_dos_factores(["secreto_dos_factores"]) --- usuarios
    usuarios_codigos_recuperacion_dos_factores(["codigos_recuperacion_dos_factores"]) --- usuarios
    usuarios_dos_factores_confirmado_en(["dos_factores_confirmado_en"]) --- usuarios
    validaciones_silabo["VALIDACIONES SILABO"]
    validaciones_silabo_id(["<u>id</u>"]) --- validaciones_silabo
    validaciones_silabo_version_reglas(["version_reglas"]) --- validaciones_silabo
    validaciones_silabo_estado_validacion(["estado_validacion"]) --- validaciones_silabo
    validaciones_silabo_version_borrador(["version_borrador"]) --- validaciones_silabo
    validaciones_silabo_cantidad_errores_validacion(["cantidad_errores_validacion"]) --- validaciones_silabo
    validaciones_silabo_cantidad_advertencias_validacion(["cantidad_advertencias_validacion"]) --- validaciones_silabo
    validaciones_silabo_completado_en(["completado_en"]) --- validaciones_silabo
    valores_campo["VALORES CAMPO"]
    valores_campo_id(["<u>id</u>"]) --- valores_campo
    valores_campo_valor_campo(["valor_campo"]) --- valores_campo
    r0{"Reemplaza"}
    aprobaciones ---|"1"| r0
    r0 ---|"N"| aprobaciones
    r1{"Aprueba"}
    usuarios ---|"1"| r1
    r1 ---|"N"| aprobaciones
    r2{"Tiene"}
    revisiones_silabo ---|"1"| r2
    r2 ---|"1"| aprobaciones
    r3{"Tiene"}
    carreras ---|"1"| r3
    r3 ---|"N"| archivos
    r4{"Sube"}
    usuarios ---|"1"| r4
    r4 ---|"N"| archivos
    r5{"Tiene"}
    asignaciones_rol ---|"1"| r5
    r5 ---|"N"| asignaciones_paralelo
    r6{"Tiene"}
    paralelos ---|"1"| r6
    r6 ---|"N"| asignaciones_paralelo
    r7{"Tiene"}
    carreras ---|"1"| r7
    r7 ---|"N"| asignaciones_rol
    r8{"Pertenece a"}
    usuarios ---|"1"| r8
    r8 ---|"N"| asignaciones_rol
    r9{"Tiene"}
    carreras ---|"1"| r9
    r9 ---|"N"| asignaturas
    r10{"Tiene"}
    asignaturas ---|"1"| r10
    r10 ---|"N"| asignaturas_ofertadas
    r11{"Tiene"}
    periodos_academicos ---|"1"| r11
    r11 ---|"N"| asignaturas_ofertadas
    r12{"Tiene"}
    plantilla_silabo ---|"1"| r12
    r12 ---|"N"| campos_plantilla
    r13{"Tiene"}
    secciones_plantilla ---|"1"| r13
    r13 ---|"N"| campos_plantilla
    r14{"Tiene"}
    facultades ---|"1"| r14
    r14 ---|"N"| carreras
    r15{"Tiene"}
    carreras ---|"1"| r15
    r15 ---|"N"| convocatorias_carreras
    r16{"Tiene"}
    convocatorias_universidad ---|"1"| r16
    r16 ---|"N"| convocatorias_carreras
    r17{"Tiene"}
    periodos_academicos ---|"1"| r17
    r17 ---|"N"| convocatorias_universidad
    r18{"Tiene"}
    campos_plantilla ---|"1"| r18
    r18 ---|"N"| definiciones_campo
    r19{"Tiene"}
    plantilla_silabo ---|"1"| r19
    r19 ---|"N"| definiciones_campo
    r20{"Envía"}
    usuarios ---|"1"| r20
    r20 ---|"N"| envios_biblioteca
    r21{"Tiene"}
    revisiones_silabo ---|"1"| r21
    r21 ---|"1"| envios_biblioteca
    r22{"Verifica"}
    usuarios ---|"1"| r22
    r22 ---|"N"| envios_biblioteca
    r23{"Tiene"}
    asignaciones_rol ---|"1"| r23
    r23 ---|"N"| eventos_auditoria
    r24{"Pertenece a"}
    usuarios ---|"1"| r24
    r24 ---|"N"| eventos_auditoria
    r25{"Genera PDF"}
    archivos ---|"1"| r25
    r25 ---|"1"| exportaciones_silabo
    r26{"Genera Word"}
    archivos ---|"1"| r26
    r26 ---|"1"| exportaciones_silabo
    r27{"Tiene"}
    revisiones_silabo ---|"1"| r27
    r27 ---|"N"| exportaciones_silabo
    r28{"Solicita"}
    usuarios ---|"1"| r28
    r28 ---|"N"| exportaciones_silabo
    r29{"Procesa"}
    trabajos_en_cola ---|"1"| r29
    r29 ---|"1"| exportaciones_silabo
    r30{"Usa como logo"}
    archivos ---|"1"| r30
    r30 ---|"N"| facultades
    r31{"Firma en"}
    archivos ---|"1"| r31
    r31 ---|"1"| firmas_documento
    r32{"Tiene"}
    exportaciones_silabo ---|"1"| r32
    r32 ---|"N"| firmas_documento
    r33{"Firma"}
    usuarios ---|"1"| r33
    r33 ---|"N"| firmas_documento
    r34{"Emite"}
    usuarios ---|"1"| r34
    r34 ---|"N"| invitaciones_activacion
    r35{"Pertenece a"}
    usuarios ---|"1"| r35
    r35 ---|"N"| invitaciones_activacion
    r36{"Tiene"}
    silabos ---|"1"| r36
    r36 ---|"N"| mensajes_ia
    r37{"Pertenece a"}
    usuarios ---|"1"| r37
    r37 ---|"N"| mensajes_ia
    r38{"Pertenece a"}
    usuarios ---|"1"| r38
    r38 ---|"N"| notificaciones
    r39{"Crea"}
    usuarios ---|"1"| r39
    r39 ---|"N"| observaciones_revision
    r40{"Tiene"}
    revisiones_silabo ---|"1"| r40
    r40 ---|"N"| observaciones_revision
    r41{"Tiene"}
    asignaturas_ofertadas ---|"1"| r41
    r41 ---|"N"| paralelos
    r42{"Tiene"}
    aprobaciones ---|"1"| r42
    r42 ---|"1"| reaperturas
    r43{"Reabre"}
    usuarios ---|"1"| r43
    r43 ---|"N"| reaperturas
    r44{"Reabre"}
    revisiones_silabo ---|"1"| r44
    r44 ---|"N"| reaperturas
    r45{"Tiene"}
    asignaturas ---|"1"| r45
    r45 ---|"N"| requisitos_asignatura
    r46{"Es requisito de"}
    asignaturas ---|"1"| r46
    r46 ---|"N"| requisitos_asignatura
    r47{"Tiene"}
    definiciones_campo ---|"1"| r47
    r47 ---|"N"| resultados_validacion
    r48{"Tiene"}
    validaciones_silabo ---|"1"| r48
    r48 ---|"N"| resultados_validacion
    r49{"Envía"}
    usuarios ---|"1"| r49
    r49 ---|"N"| revisiones_silabo
    r50{"Tiene"}
    reaperturas ---|"1"| r50
    r50 ---|"N"| revisiones_silabo
    r51{"Sucede a"}
    revisiones_silabo ---|"1"| r51
    r51 ---|"N"| revisiones_silabo
    r52{"Tiene"}
    silabos ---|"1"| r52
    r52 ---|"N"| revisiones_silabo
    r53{"Tiene"}
    plantilla_silabo ---|"1"| r53
    r53 ---|"N"| secciones_plantilla
    r54{"Tiene"}
    convocatorias_carreras ---|"1"| r54
    r54 ---|"N"| silabos
    r55{"Tiene"}
    paralelos ---|"1"| r55
    r55 ---|"N"| silabos
    r56{"Tiene"}
    revisiones_silabo ---|"1"| r56
    r56 ---|"1"| solicitudes_correccion
    r57{"Solicita"}
    usuarios ---|"1"| r57
    r57 ---|"N"| solicitudes_correccion
    r58{"Ejecuta"}
    usuarios ---|"1"| r58
    r58 ---|"N"| validaciones_silabo
    r59{"Tiene"}
    silabos ---|"1"| r59
    r59 ---|"N"| validaciones_silabo
    r60{"Tiene"}
    definiciones_campo ---|"1"| r60
    r60 ---|"N"| valores_campo
    r61{"Tiene"}
    silabos ---|"1"| r61
    r61 ---|"N"| valores_campo
    aprobaciones_aprobacion_anterior_id(["aprobacion_anterior_id (FK)"]) --- aprobaciones
    aprobaciones_aprobado_por(["aprobado_por (FK)"]) --- aprobaciones
    aprobaciones_revision_silabo_id(["revision_silabo_id (FK)"]) --- aprobaciones
    archivos_carrera_id(["carrera_id (FK)"]) --- archivos
    archivos_subido_por(["subido_por (FK)"]) --- archivos
    asignaciones_paralelo_asignacion_rol_id(["asignacion_rol_id (FK)"]) --- asignaciones_paralelo
    asignaciones_paralelo_paralelo_id(["paralelo_id (FK)"]) --- asignaciones_paralelo
    asignaciones_rol_carrera_id(["carrera_id (FK)"]) --- asignaciones_rol
    asignaciones_rol_usuario_id(["usuario_id (FK)"]) --- asignaciones_rol
    asignaturas_carrera_id(["carrera_id (FK)"]) --- asignaturas
    asignaturas_ofertadas_asignatura_id(["asignatura_id (FK)"]) --- asignaturas_ofertadas
    asignaturas_ofertadas_periodo_academico_id(["periodo_academico_id (FK)"]) --- asignaturas_ofertadas
    campos_plantilla_plantilla_id(["plantilla_id (FK)"]) --- campos_plantilla
    campos_plantilla_seccion_plantilla_id(["seccion_plantilla_id (FK)"]) --- campos_plantilla
    carreras_facultad_id(["facultad_id (FK)"]) --- carreras
    convocatorias_carreras_carrera_id(["carrera_id (FK)"]) --- convocatorias_carreras
    convocatorias_carreras_convocatoria_universidad_id(["convocatoria_universidad_id (FK)"]) --- convocatorias_carreras
    convocatorias_universidad_periodo_academico_id(["periodo_academico_id (FK)"]) --- convocatorias_universidad
    definiciones_campo_campo_plantilla_id(["campo_plantilla_id (FK)"]) --- definiciones_campo
    definiciones_campo_plantilla_id(["plantilla_id (FK)"]) --- definiciones_campo
    envios_biblioteca_enviado_por(["enviado_por (FK)"]) --- envios_biblioteca
    envios_biblioteca_revision_silabo_id(["revision_silabo_id (FK)"]) --- envios_biblioteca
    envios_biblioteca_verificado_por(["verificado_por (FK)"]) --- envios_biblioteca
    eventos_auditoria_asignacion_rol_id(["asignacion_rol_id (FK)"]) --- eventos_auditoria
    eventos_auditoria_usuario_id(["usuario_id (FK)"]) --- eventos_auditoria
    exportaciones_silabo_archivo_pdf_id(["archivo_pdf_id (FK)"]) --- exportaciones_silabo
    exportaciones_silabo_archivo_word_id(["archivo_word_id (FK)"]) --- exportaciones_silabo
    exportaciones_silabo_revision_silabo_id(["revision_silabo_id (FK)"]) --- exportaciones_silabo
    exportaciones_silabo_solicitado_por(["solicitado_por (FK)"]) --- exportaciones_silabo
    exportaciones_silabo_trabajo_id(["trabajo_id (FK)"]) --- exportaciones_silabo
    facultades_logo_archivo_id(["logo_archivo_id (FK)"]) --- facultades
    firmas_documento_archivo_firmado_id(["archivo_firmado_id (FK)"]) --- firmas_documento
    firmas_documento_exportacion_silabo_id(["exportacion_silabo_id (FK)"]) --- firmas_documento
    firmas_documento_usuario_id(["usuario_id (FK)"]) --- firmas_documento
    invitaciones_activacion_emitida_por_usuario_id(["emitida_por_usuario_id (FK)"]) --- invitaciones_activacion
    invitaciones_activacion_usuario_id(["usuario_id (FK)"]) --- invitaciones_activacion
    mensajes_ia_silabo_id(["silabo_id (FK)"]) --- mensajes_ia
    mensajes_ia_usuario_id(["usuario_id (FK)"]) --- mensajes_ia
    notificaciones_usuario_id(["usuario_id (FK)"]) --- notificaciones
    observaciones_revision_creado_por(["creado_por (FK)"]) --- observaciones_revision
    observaciones_revision_revision_silabo_id(["revision_silabo_id (FK)"]) --- observaciones_revision
    paralelos_asignatura_ofertada_id(["asignatura_ofertada_id (FK)"]) --- paralelos
    reaperturas_aprobacion_id(["aprobacion_id (FK)"]) --- reaperturas
    reaperturas_reabierto_por(["reabierto_por (FK)"]) --- reaperturas
    reaperturas_revision_aprobada_id(["revision_aprobada_id (FK)"]) --- reaperturas
    requisitos_asignatura_asignatura_id(["asignatura_id (FK)"]) --- requisitos_asignatura
    requisitos_asignatura_requisito_id(["requisito_id (FK)"]) --- requisitos_asignatura
    resultados_validacion_definicion_campo_id(["definicion_campo_id (FK)"]) --- resultados_validacion
    resultados_validacion_validacion_silabo_id(["validacion_silabo_id (FK)"]) --- resultados_validacion
    revisiones_silabo_enviado_por(["enviado_por (FK)"]) --- revisiones_silabo
    revisiones_silabo_reapertura_id(["reapertura_id (FK)"]) --- revisiones_silabo
    revisiones_silabo_revision_anterior_id(["revision_anterior_id (FK)"]) --- revisiones_silabo
    revisiones_silabo_silabo_id(["silabo_id (FK)"]) --- revisiones_silabo
    secciones_plantilla_plantilla_id(["plantilla_id (FK)"]) --- secciones_plantilla
    silabos_convocatoria_carrera_id(["convocatoria_carrera_id (FK)"]) --- silabos
    silabos_paralelo_id(["paralelo_id (FK)"]) --- silabos
    solicitudes_correccion_revision_silabo_id(["revision_silabo_id (FK)"]) --- solicitudes_correccion
    solicitudes_correccion_solicitado_por(["solicitado_por (FK)"]) --- solicitudes_correccion
    validaciones_silabo_ejecutado_por(["ejecutado_por (FK)"]) --- validaciones_silabo
    validaciones_silabo_silabo_id(["silabo_id (FK)"]) --- validaciones_silabo
    valores_campo_definicion_campo_id(["definicion_campo_id (FK)"]) --- valores_campo
    valores_campo_silabo_id(["silabo_id (FK)"]) --- valores_campo
    classDef entidad fill:#FFFFFF,stroke:#000,color:#000
    classDef relacion fill:#FFFFFF,stroke:#000,color:#000
    classDef atributo fill:#FFFFFF,stroke:#000,color:#000
    class aprobaciones,archivos,asignaciones_paralelo,asignaciones_rol,asignaturas,asignaturas_ofertadas,campos_plantilla,carreras,convocatorias_carreras,convocatorias_universidad,definiciones_campo,envios_biblioteca,eventos_auditoria,exportaciones_silabo,facultades,firmas_documento,invitaciones_activacion,mensajes_ia,notificaciones,observaciones_revision,paralelos,periodos_academicos,plantilla_silabo,reaperturas,requisitos_asignatura,resultados_validacion,revisiones_silabo,secciones_plantilla,silabos,solicitudes_correccion,trabajos_en_cola,usuarios,validaciones_silabo,valores_campo entidad
    class r0,r1,r2,r3,r4,r5,r6,r7,r8,r9,r10,r11,r12,r13,r14,r15,r16,r17,r18,r19,r20,r21,r22,r23,r24,r25,r26,r27,r28,r29,r30,r31,r32,r33,r34,r35,r36,r37,r38,r39,r40,r41,r42,r43,r44,r45,r46,r47,r48,r49,r50,r51,r52,r53,r54,r55,r56,r57,r58,r59,r60,r61 relacion
    class aprobaciones_id,aprobaciones_clave_solicitud,aprobaciones_hash_contenido,aprobaciones_aprobado_en,archivos_id,archivos_disco,archivos_ruta_archivo,archivos_nombre_archivo,archivos_tipo_archivo,archivos_tamano_bytes,archivos_hash_archivo,archivos_categoria,archivos_almacenado_en,asignaciones_paralelo_id,asignaciones_paralelo_asignacion_paralelo_activa,asignaciones_paralelo_asignado_en,asignaciones_rol_id,asignaciones_rol_asignacion_rol_activa,asignaciones_rol_asignado_en,asignaciones_rol_rol,asignaturas_id,asignaturas_codigo_asignatura,asignaturas_nombre_asignatura,asignaturas_ciclo_asignatura,asignaturas_creditos_asignatura,asignaturas_asignatura_activa,asignaturas_horas_docencia,asignaturas_horas_practicas,asignaturas_horas_autonomas,asignaturas_orden_asignatura_en_ciclo,asignaturas_unidad_curricular_asignatura,asignaturas_modalidad_asignatura,asignaturas_total_horas_asignatura,asignaturas_ofertadas_id,asignaturas_ofertadas_asignatura_ofertada_activa,campos_plantilla_id,campos_plantilla_clave_campo_plantilla,campos_plantilla_titulo_campo_plantilla,campos_plantilla_posicion_campo_plantilla,campos_plantilla_configuracion_campo_plantilla,carreras_id,carreras_codigo_carrera,carreras_nombre_carrera,carreras_carrera_activa,carreras_modalidad_carrera,carreras_numero_ciclos,convocatorias_carreras_id,convocatorias_carreras_estado_convocatoria_carrera,convocatorias_universidad_id,convocatorias_universidad_fecha_inicio,convocatorias_universidad_fecha_entrega,convocatorias_universidad_estado_convocatoria_universidad,definiciones_campo_id,definiciones_campo_clave_definicion_campo,definiciones_campo_etiqueta_definicion_campo,definiciones_campo_ayuda_definicion_campo,definiciones_campo_tipo_definicion_campo,definiciones_campo_heredado_definicion_campo,definiciones_campo_origen_dato_definicion_campo,definiciones_campo_editable_docente_definicion_campo,definiciones_campo_opciones_definicion_campo,definiciones_campo_posicion_definicion_campo,definiciones_campo_condicion_definicion_campo,envios_biblioteca_id,envios_biblioteca_enviado_en,envios_biblioteca_verificado_en,eventos_auditoria_id,eventos_auditoria_accion,eventos_auditoria_tipo_recurso,eventos_auditoria_recurso_id,eventos_auditoria_resultado,eventos_auditoria_detalles,eventos_auditoria_ocurrido_en,exportaciones_silabo_id,exportaciones_silabo_clave_solicitud,exportaciones_silabo_estado_exportacion,exportaciones_silabo_solicitado_en,exportaciones_silabo_completado_en,facultades_id,facultades_nombre_facultad,facultades_facultad_activa,facultades_campus_facultad,firmas_documento_id,firmas_documento_firmado_como,firmas_documento_nombre_en_certificado,firmas_documento_numero_certificado,firmas_documento_entidad_emisora,firmas_documento_certificado_valido_hasta,firmas_documento_hash_archivo,firmas_documento_firmado_en,invitaciones_activacion_id,invitaciones_activacion_hash_activacion,invitaciones_activacion_expira_en,invitaciones_activacion_usada_en,invitaciones_activacion_invalidada_en,invitaciones_activacion_creada_en,mensajes_ia_id,mensajes_ia_contenido_mensaje,mensajes_ia_enviado_en,notificaciones_id,notificaciones_clave_unica,notificaciones_tipo_notificacion,notificaciones_titulo_notificacion,notificaciones_mensaje_notificacion,notificaciones_tipo_recurso,notificaciones_recurso_id,notificaciones_leido_en,notificaciones_notificado_en,observaciones_revision_id,observaciones_revision_clave_seccion,observaciones_revision_clave_campo,observaciones_revision_contenido_observacion_revision,observaciones_revision_estado_observacion_revision,observaciones_revision_observado_en,paralelos_id,paralelos_codigo_paralelo,paralelos_jornada_paralelo,periodos_academicos_id,periodos_academicos_codigo_periodo_academico,periodos_academicos_fecha_inicio_periodo,periodos_academicos_fecha_fin_periodo,periodos_academicos_cantidad_semanas_lectivas,plantilla_silabo_id,plantilla_silabo_mapeo_documento_plantilla,reaperturas_id,reaperturas_clave_solicitud,reaperturas_causa,reaperturas_reabierto_en,reaperturas_origen_reapertura,requisitos_asignatura_id,requisitos_asignatura_tipo_requisito_asignatura,resultados_validacion_id,resultados_validacion_codigo_resultado_validacion,resultados_validacion_severidad_resultado_validacion,resultados_validacion_mensaje_resultado_validacion,revisiones_silabo_id,revisiones_silabo_numero_revision,revisiones_silabo_clave_solicitud,revisiones_silabo_version_borrador,revisiones_silabo_contenido_enviado,revisiones_silabo_hash_contenido,revisiones_silabo_enviado_en,secciones_plantilla_id,secciones_plantilla_clave_seccion_plantilla,secciones_plantilla_titulo_seccion_plantilla,secciones_plantilla_descripcion_seccion_plantilla,secciones_plantilla_posicion_seccion_plantilla,silabos_id,silabos_estado_silabo,silabos_version_borrador,silabos_iniciado_en,silabos_guardado_en,silabos_datos_academicos_silabo,solicitudes_correccion_id,solicitudes_correccion_justificacion_solicitud_correccion,solicitudes_correccion_solicitado_en,trabajos_en_cola_id,trabajos_en_cola_tipo_trabajo,trabajos_en_cola_estado_trabajo,trabajos_en_cola_clave_solicitud,trabajos_en_cola_intentos,trabajos_en_cola_progreso,trabajos_en_cola_resultado_trabajo,trabajos_en_cola_codigo_error,trabajos_en_cola_mensaje_error,trabajos_en_cola_iniciado_en,trabajos_en_cola_finalizado_en,trabajos_en_cola_encolado_en,trabajos_en_cola_cola,trabajos_en_cola_tipo_recurso,trabajos_en_cola_recurso_id,trabajos_en_cola_intentos_maximos,trabajos_en_cola_datos_entrada,usuarios_id,usuarios_nombre_usuario,usuarios_correo_electronico,usuarios_correo_verificado_en,usuarios_contrasena,usuarios_usuario_activo,usuarios_codigo_recordarme,usuarios_secreto_dos_factores,usuarios_codigos_recuperacion_dos_factores,usuarios_dos_factores_confirmado_en,validaciones_silabo_id,validaciones_silabo_version_reglas,validaciones_silabo_estado_validacion,validaciones_silabo_version_borrador,validaciones_silabo_cantidad_errores_validacion,validaciones_silabo_cantidad_advertencias_validacion,validaciones_silabo_completado_en,valores_campo_id,valores_campo_valor_campo,aprobaciones_aprobacion_anterior_id,aprobaciones_aprobado_por,aprobaciones_revision_silabo_id,archivos_carrera_id,archivos_subido_por,asignaciones_paralelo_asignacion_rol_id,asignaciones_paralelo_paralelo_id,asignaciones_rol_carrera_id,asignaciones_rol_usuario_id,asignaturas_carrera_id,asignaturas_ofertadas_asignatura_id,asignaturas_ofertadas_periodo_academico_id,campos_plantilla_plantilla_id,campos_plantilla_seccion_plantilla_id,carreras_facultad_id,convocatorias_carreras_carrera_id,convocatorias_carreras_convocatoria_universidad_id,convocatorias_universidad_periodo_academico_id,definiciones_campo_campo_plantilla_id,definiciones_campo_plantilla_id,envios_biblioteca_enviado_por,envios_biblioteca_revision_silabo_id,envios_biblioteca_verificado_por,eventos_auditoria_asignacion_rol_id,eventos_auditoria_usuario_id,exportaciones_silabo_archivo_pdf_id,exportaciones_silabo_archivo_word_id,exportaciones_silabo_revision_silabo_id,exportaciones_silabo_solicitado_por,exportaciones_silabo_trabajo_id,facultades_logo_archivo_id,firmas_documento_archivo_firmado_id,firmas_documento_exportacion_silabo_id,firmas_documento_usuario_id,invitaciones_activacion_emitida_por_usuario_id,invitaciones_activacion_usuario_id,mensajes_ia_silabo_id,mensajes_ia_usuario_id,notificaciones_usuario_id,observaciones_revision_creado_por,observaciones_revision_revision_silabo_id,paralelos_asignatura_ofertada_id,reaperturas_aprobacion_id,reaperturas_reabierto_por,reaperturas_revision_aprobada_id,requisitos_asignatura_asignatura_id,requisitos_asignatura_requisito_id,resultados_validacion_definicion_campo_id,resultados_validacion_validacion_silabo_id,revisiones_silabo_enviado_por,revisiones_silabo_reapertura_id,revisiones_silabo_revision_anterior_id,revisiones_silabo_silabo_id,secciones_plantilla_plantilla_id,silabos_convocatoria_carrera_id,silabos_paralelo_id,solicitudes_correccion_revision_silabo_id,solicitudes_correccion_solicitado_por,validaciones_silabo_ejecutado_por,validaciones_silabo_silabo_id,valores_campo_definicion_campo_id,valores_campo_silabo_id atributo
```
