# Fase 33b — Migrar `zod` 3 → 4 (aviso de severidad alta)

Estado: **implementada en local (2026-11-19), decisión A de Luis; PR del frontend pendiente de subir.**

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

## Resultado (2026-11-19)
- Riesgo 1 resuelto: `@hookform/resolvers` 7.0.7 declara soporte para `zod` 3.25 y 4; se subió junto con `zod` 4.6.7.
- Solo 6 archivos necesitaron cambios (no los 19): `invalid_type_error` → `error` (3 paneles), y los formularios con `z.coerce`/`z.preprocess` pasan a `useForm<Entrada, unknown, Salida>` porque en zod 4 la entrada de esos campos es `unknown`; el `pipe` de porcentaje usa `transform(Number)` en vez de `coerce`.
- Verificado: `tsc` limpio, vitest 265 archivos / 3017 tests, `infra/audit.sh --strict` del frontend sin altas (salida 0; quedan 2 moderadas de `react-router`), y Playwright contra `localhost:8092`: login y «olvidé mi contraseña» muestran «Email inválido» como antes.
- No ejecutado: `npm run e2e:humo` ni recorrido de cada formulario de Compras/Ajustes en navegador.
- Abierto: el comentario de `ProviderFormDrawer` sobre `refine` que se salta con `invalid_type` describe el comportamiento de zod 3; en zod 4 puede mostrarse más de un mensaje a la vez (no empeora nada; los tests lo cubren).
- 33c opcional: `react-router-dom` 6 → 7 (2 moderadas).
