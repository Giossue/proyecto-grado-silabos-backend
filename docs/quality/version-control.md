# Control de versiones

## Commits

- Una unidad coherente por commit.
- Asunto en español: `tipo(alcance): descripción`, de preferencia hasta 72 caracteres.
- Tipos: `feat`, `fix`, `test`, `docs`, `refactor`, `perf`, `build`, `chore`,
  `security`.
- El cuerpo explica motivo/riesgo cuando el diff no es autoexplicativo.
- No combines formateo masivo con cambio funcional.

Ejemplos:

```text
feat(proyecto-carrera): Coordinación decide si el docente puede editar un campo del proyecto de carrera
docs(plantilla): el manual indica el botón Editar JSON del admin
```

## Ramas y PR

- Un push a `main` despliega en Dokploy y ejecuta las migraciones. No hay CI: corre las
  verificaciones de `testing.md` antes de subir.
- Ramas cortas desde una base actualizada.
- PR pequeño con resultado, trazabilidad, capturas/evidencia, migración y riesgos.
- No reescribas historia publicada sin coordinación explícita.
- El agente hace commit y push al terminar un cambio verificado; no reescribe historia sin petición.

## Nunca versionar

`.env`, credenciales, tokens, dumps, archivos productivos, transcripciones sensibles,
exports reales, logs con datos personales, dependencias/builds generados o claves privadas.

## Cambios generados

Lockfiles sí se versionan con la causa correspondiente. Artefactos generados se versionan
solo si el repositorio lo decide explícitamente y pueden reproducirse/validarse.

## Planes y decisiones

- Un plan vive en `docs/plans/active/` solo mientras el trabajo está en curso. Al
  terminar se borra; su historia queda en git.
- Las decisiones van a `DECISIONS_STATUS.md`; lo que falta decidir, a
  `docs/plans/pendientes.md`.
- El comportamiento observable y sus pruebas van a `docs/quality/traceability.md`.
