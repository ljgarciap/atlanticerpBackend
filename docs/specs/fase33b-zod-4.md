# Fase 33b — Migrar `zod` 3 → 4 (aviso de severidad alta)

Estado: **borrador para aprobar (2026-11-19).** Nada se implementa sin el «adelante» de Luis.

## Por qué
`npm audit` (producción) marca `zod` 3.25.76 como **alta**; el arreglo exige `zod` 4.x (cambio mayor).
Es la única alta que le queda al frontend tras la Fase 33a; mientras exista, la auditoría semanal
sale en rojo cada lunes.

## Alcance medido (frontend)
- 19 archivos importan `zod`: 11 componentes de formularios/paneles y 8 páginas (login, cambio y
  restablecimiento de clave, perfil de empresa, cuentas bancarias, departamentos, etc.).
- Uso: ~81 `z.string`, 24 `z.object`, 22 `z.infer`, 19 `.refine`, 12 `z.enum`, 11 `z.coerce`,
  2 `.superRefine`, 1 `.flatten`. Casi todo con `@hookform/resolvers` 3.x.

## Riesgos conocidos (a confirmar en la implementación, no verificados todavía)
1. `@hookform/resolvers` 3.x probablemente **no** habla con `zod` 4: habría que subir el resolver
   (otra mayor) y revisar `react-hook-form`.
2. Zod 4 cambia mensajes de error por defecto y la forma de personalizarlos (`required_error` /
   `invalid_type_error` pasan a `error`); los textos que ve la persona o que prueban los tests
   pueden cambiar. Hay 244 usos de `.message`.
3. `.flatten`, `.email`/`.url`/`.uuid` (formatos como funciones de primer nivel) y `z.coerce`
   tienen cambios de tipo/comportamiento.

## Plan (si se aprueba)
1. Rama de prueba: subir `zod` y el resolver; `tsc` para listar roturas.
2. Migrar los 19 archivos manteniendo **los mismos textos de error** en español/inglés (i18n).
3. Verificación: `tsc`, vitest completo, humo `npm run e2e:humo` y recorrido con Playwright de
   login, cambio de clave, restablecer clave y un formulario por módulo (antes/después).
4. Un PR solo en el frontend.

## Decisión de Luis
- **A (recomendada):** migrar ahora, antes de la Fase 27 (toca las pantallas de clave y MFA que la 27 también toca).
- **B:** posponer y aceptar la auditoría en rojo (o bajar el umbral de `--strict` a críticas) hasta después de la 27.

## Criterios de aceptación
- `infra/audit.sh --strict` del frontend sin altas ni críticas (queda `react-router` moderada → 33c opcional).
- Mismos mensajes de validación visibles que antes; suite y humo en verde.
