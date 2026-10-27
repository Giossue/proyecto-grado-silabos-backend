# Unidades escritas por el docente

Decisión del responsable del producto (2026-10-27), siguiendo a los antecedentes (Meza Luque, 2022;
Llumitaxi Chacha y Morocho Tualombo, 2019): el docente escribe el nombre y el resultado de cada
unidad en su sílabo. La ficha de Coordinación deja de tener «Unidades y resultados de aprendizaje».

## Pasos

1. Reglas de bloque: comprobación `filled`, que cuenta solo los datos llenos, en backend y frontend.
2. Plantilla oficial: `unidades.nombre` y `unidades.resultado` sin `from`; regla de envío que exige
   los 4 nombres y los 4 resultados; sin el campo ni la plantilla lista `unidades_curriculares`.
3. `ProtectTemplateResponses` lleva la plantilla activa a ese formato y retira el campo de la ficha;
   una migración nueva la ejecuta al desplegar (solo sin sílabos y con la plantilla editable).
4. Pruebas, conformidad de bloques, Word de referencia, documentación, manuales y tesis.

`from` sigue disponible en el lenguaje de la plantilla, pero la plantilla oficial no lo usa.
