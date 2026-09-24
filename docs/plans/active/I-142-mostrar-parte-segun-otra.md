# I-142: «Mostrar solo si» en partes del docente

## Estado

Implementado localmente (backend y frontend). Migración
`2026_09_24_000142_mostrar_parte_segun_otra` pendiente de aplicar en la base remota de
pruebas; no se ejecutó nada contra ella.

## Trazabilidad

RF-017..026, RN-009..012, CU-04, CU-05, ADM-06. No depende de una decisión
`POR VALIDAR`.

## Reglas

1. Una parte que llena el docente puede tener una sola condición: otra parte Sí/No o de
   selección de la misma plantilla es igual a un valor. Se guarda en
   `definiciones_campo.mostrar_si` como `{key, value}`; `null` es «Siempre». Un Sí/No
   compara con `true`/`false`.
2. Se valida al guardar (`SaveFieldDefinitionRequest.show_when`,
   `SaveTemplateDocumentRequest.properties.*.show_when`): la parte referida existe, es
   de Sí/No o selección, el valor es una de sus opciones y no es la misma parte.
3. Mientras la condición no se cumple, la parte se oculta en la hoja del docente, no es
   obligatoria al enviar y sale vacía en Word/PDF y en la ficha. Su valor no se borra.
   Una condición que apunta a una parte borrada o que dejó de ser de selección no oculta
   nada.
4. Todo campo visible del docente sigue obligatorio. No cambia el guardado automático ni
   la firma electrónica.
5. La plantilla por defecto (`config/syllabus_template.php`) y la guardada (migración
   idempotente `000142`) piden «Tipo de discapacidad» y «Adaptación curricular» solo si
   «Estudiantes con discapacidad» es «Sí».
