# Módulo Gerencia — schema `gerencia`

> Movido desde `atlanticerp/CLAUDE.md` (`/doctor` 2026-09-07, migración a carga perezosa — este archivo
> solo carga cuando se trabaja bajo `app/Modules/Gerencia/`). Ver también `atlanticerp/CLAUDE.md` para
> convenciones globales del proyecto.

> Agregado 2026-08-28 — la épica (SCRUM-325, 17 tickets REQ-094→110) se venía tratando como "epic
> de otro equipo" (Pre-QA solo auditaba a pedido puntual de Luis, sin implementar código) en varias
> sesiones entre 2026-08-23 y 08-25; el criterio cambió de hecho a partir del batch de fixes del
> 2026-08-26 sin quedar como decisión explícita en ningún lado — confirmado con Luis el 2026-08-28
> que la épica pasa a ser parte normal del roster de AtlanticERP de acá en adelante. Historial completo
> de Pre-QA (9 rondas, v2→v9) recuperado el mismo día en
> `docs/pre-qa/gerencia-epic-scrum325/` (vivía fuera de cualquier repo git hasta entonces).

Dashboard ejecutivo de un solo vistazo (`/gerencia`, rol `management`) que agrega datos ya
existentes de otros módulos — **greenfield casi total sin tabla propia** hasta REQ-101 (reglas de
aprobación), primer y único par de tablas reales del módulo:

```sql
reglas_aprobacion(id, tipo, activo, observaciones, created_at, updated_at)
-- tipo: purchase_order | zona_libre | general_count | adjustment | relocation — qué tipo de
-- solicitud puede aprobar cada regla. La matriz real (qué persona aprueba qué tipo) la carga el
-- superadmin vía CRUD (ReglaAprobacionController) — nunca hardcodeada.

regla_aprobacion_aprobadores(regla_aprobacion_id, user_id)  -- PK compuesta, N:M
```

`GerenciaHomeService` (única fuente del payload de `/api/gerencia/home`) agrega, sin tabla propia:
KPIs de Facturación/Proyectos (Admin&Cont, Ventas&Diseño), "Salud por módulo" de VD/Admin&Cont/
Compras/Bodega/Servicios (cada tarjeta reusa el mismo cálculo que la pantalla nativa del módulo,
nunca reimplementa la métrica en paralelo — regla dura tras el hallazgo real de SCRUM-168 v9, ver
`docs/pre-qa/gerencia-epic-scrum325/gerencia-scrum168-20260826-v9-recheck.md`), bandeja de
aprobaciones pendientes (`GerenciaAprobacionController`, filtra por `tipo` contra las
`reglas_aprobacion` activas del actor), "Mi calendario" (mismo endpoint compartido de Outlook que
Admin&Cont/Servicios/Compras/Bodega), y agendas del día de Bodega/Servicios.

**Estado (2026-08-28):** 14/17 tickets en `UAT`, 3 en `PM Review` (SCRUM-157 "CxC al día",
SCRUM-159 "Panel Facturación", SCRUM-167 "Salud Admin&Cont") — sin cerrar del todo, sin bloqueante
conocido más allá del trámite normal de PM Review → UAT.
