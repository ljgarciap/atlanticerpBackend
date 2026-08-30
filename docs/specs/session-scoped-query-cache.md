# Spec: Limpieza del cache de datos al cambiar de sesión (Inicio/Equipo)

**Date**: 2026-07-16
**Requested by**: Luis
**Status**: Done — fix desplegado y verificado en vivo en dev.atlanticerp.ai (2026-07-16, commit `2dc7c14`)
**Project**: AtlanticERP — atlanticerp-frontend

## Problem
Al cerrar sesión con un rol especializado (ej. Diseñador/Vendedor) y volver a
iniciar sesión con otro usuario (ej. Gerencia) en la misma pestaña del
navegador, la pestaña "Inicio" de Pipeline/Cotizaciones muestra momentáneamente
los datos de la sesión ANTERIOR, no los del usuario recién logueado. El dato
correcto solo aparece si el usuario cambia de pestaña del navegador y regresa
(lo que fuerza un refetch), no al simple hecho de loguearse.

Esto es un problema de integridad de datos entre sesiones — un usuario puede
ver, aunque sea brevemente, información de "Inicio" que pertenece a otra
cuenta, en un módulo que ya maneja datos de negocio sensibles (proyectos y
cotizaciones asignados a una persona puntual).

**Nota de alcance ya verificada, no es parte del problema**: la nomenclatura
"Inicio"/"Equipo" y la restricción de "Equipo" solo para Gerencia (rol
`management`/`superadmin`) YA están correctamente implementadas en el código
actual de `dev` — verificado leyendo `PipelinePage.tsx`, `QuotesListPage.tsx` y
`HomePage.tsx`: los 3 usan `t('ventasDiseno:scope.own')` = "Inicio" y
`t('ventasDiseno:scope.team')` = "Equipo", ambos gateados por
`canSeeTeam = user?.role === 'management' || user?.role === 'superadmin'`. No
hay trabajo pendiente en esa parte — se documenta acá solo para dejar
constancia de que se revisó y está bien.

## Root cause (confirmado, no es hipótesis)
`queryClient` (TanStack Query) se instancia una sola vez como singleton de
módulo en `App.tsx:45`, con `staleTime: 30_000`. El logout (`clearAuth()` en
`authStore.ts`) solo limpia el store de Zustand y el token en `localStorage` —
nunca limpia este cache. El logout navega con `navigate('/login', { replace:
true })` (client-side, sin recargar la página), así que el `queryClient` en
memoria sobrevive intacto al cambio de usuario.

Como las query keys de las vistas afectadas (`['ventas-diseno-pipeline',
scope, ...]`, `['ventas-diseno-quotes', ...]`, etc.) no incluyen ningún
identificador de usuario, la nueva sesión reutiliza el cache de la sesión
anterior hasta que una query se refresca — lo cual explica exactamente el
síntoma reportado: cambiar de pestaña del navegador dispara
`refetchOnWindowFocus` (comportamiento default de TanStack Query), lo que
refresca los datos y "limpia" la vista.

`clearAuth()` se invoca desde 2 lugares (verificado): el botón de logout en
`TopBar.tsx` y el interceptor de 401/sesión expirada en `authApi.ts`. Ambos
caminos comparten el mismo problema.

## Users and roles
Afecta a cualquier secuencia logout→login (o expiración de sesión→re-login)
dentro de la misma pestaña del navegador, sin importar el rol. El síntoma es
más visible para Gerencia porque su "Inicio" muestra datos distintos a los de
un Diseñador/Vendedor, pero el problema de fondo (cache no limpiado) aplica a
cualquier query cacheada de la sesión anterior, no solo a Pipeline/Cotizaciones.

## Acceptance criteria
- [ ] Al cerrar sesión (botón logout) e iniciar sesión con otro usuario en la
      misma pestaña, ninguna vista (Pipeline, Cotizaciones, Inicio de Ventas &
      Diseño, y cualquier otra pantalla con datos cacheados) muestra datos de
      la sesión anterior en ningún momento, ni siquiera brevemente antes del
      refetch.
- [ ] Lo mismo aplica cuando la sesión expira (401) y el usuario vuelve a
      loguearse (con la misma cuenta u otra) desde el modal de sesión expirada.
- [ ] El comportamiento actual de "Inicio"/"Equipo" (nombres y restricción a
      Gerencia) no se modifica — ya es correcto.
- [ ] No hay regresión de performance perceptible: el primer render tras login
      sigue mostrando el loading state normal mientras las queries se
      resuelven, no una demora adicional notable.

## Edge cases and error scenarios
- Logout manual → login con el mismo usuario: debe recargar sus propios datos
  frescos igual (no debe fallar ni quedar en blanco).
- Sesión expira en background (token vencido) mientras el usuario navega →
  modal de sesión expirada → login: mismo comportamiento, sin cache viejo.
- Cambio de rol visible vía `RolePickerModal` (mismo usuario, multi-rol,
  excepción de superadmin) — **fuera de alcance de este bug**: es la misma
  persona seleccionando un rol adicional propio, no una sesión distinta; si
  amerita el mismo tratamiento se evalúa aparte.

## Out of scope
- Cualquier cambio a la nomenclatura o a las reglas de visibilidad de
  "Inicio"/"Equipo" — ya están correctas, no se tocan.
- Persistencia de filtros/estado de UI (scope, chip, búsqueda) entre sesiones
  — hoy vive en `useState` local del componente, no en el cache de queries;
  no es parte de este bug.

## Open questions
Ninguna — el root cause está confirmado por lectura de código, no requiere
decisión de negocio de Luis antes de diseñar la solución.

## References
- `atlanticerp-frontend/src/App.tsx:45` — instanciación del `queryClient`.
- `atlanticerp-frontend/src/store/authStore.ts:66` — `clearAuth()`, no limpia cache.
- `atlanticerp-frontend/src/api/authApi.ts:31` — interceptor 401 llama `clearAuth()`.
- `atlanticerp-frontend/src/components/TopBar.tsx:63` — logout manual llama `clearAuth()`.
- `atlanticerp-frontend/src/pages/ventas-diseno/PipelinePage.tsx`,
  `QuotesListPage.tsx`, `HomePage.tsx` — vistas con el toggle Inicio/Equipo,
  ya correctas en nomenclatura/permiso.
