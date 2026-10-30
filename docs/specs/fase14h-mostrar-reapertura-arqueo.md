# Fase 14h — Mostrar quién reabrió un arqueo y el motivo

**Estado**: decisiones de §5 tomadas por Luis (2026-10-30); pendiente de su «adelante con 14h». Origen: «Abierto» de la Fase 13 (2.10): «la interfaz no muestra quién reabrió ni el motivo». No se implementa sin su «adelante».

## 1. Objetivo
Reabrir un arqueo cerrado es una acción sensible (solo el aprobador o su suplente, con motivo de al menos 10 caracteres). Hoy queda guardada y auditada, pero quien mira el arqueo no ve que fue reabierto ni por qué. Que la pantalla lo diga.

## 2. Hallazgos (medidos sobre `master`)
- **Se guarda** en `daily_cash_counts`: `reabierto_por`, `reabierto_at`, `reabierto_motivo`. Es la **última** reapertura; el historial completo está en `audit_logs` (acción `admin_contab.daily_cash_count.reopened`).
- **La API ya lo envía** en el detalle y en el arqueo activo (`DailyCashCountService::present()`): `reabierto_por` (nombre), `reabierto_at` e `reabierto_motivo`. El tipo del frontend ya los declara.
- **Ninguna pantalla los usa** (`ArqueoCajaPage`): el detalle de un día solo dice «Cerrado por…» y «Aprobado por…».
- **El listado de historial NO los trae** (`DailyCashCountService::history()` devuelve número, fecha, total, estado, quién cerró y quién aprobó).
- Un arqueo reabierto conserva su número al volver a cerrarse, así que no hay otra pista visible de que se reabrió.

## 3. Diseño propuesto
- **Detalle del día**: lista de TODAS las reaperturas (más reciente primero): «Reabierto por {nombre} el {fecha} — {motivo}». El detalle de la API suma `reaperturas: [{por, at, motivo}]`, leído de `audit_logs` (acción `admin_contab.daily_cash_count.reopened`, `entity_id` = el arqueo, `metadata.motivo`): la auditoría es inmutable y tiene respaldo (ADR-SCRUM815), así que no hace falta una tabla nueva. Los campos `reabierto_*` de la última se conservan.
- **Arqueo activo reabierto** (frontend): banner ámbar sobre el arqueo abierto con la última reapertura (y el motivo), para que quien lo vuelva a cerrar sepa por qué está abierto.
- **Listado de historial** (backend + frontend): `history()` agrega `reabierto` (booleano) y el listado muestra una marca discreta «Reabierto» en la fila, con el motivo en el tooltip (decisión 1).
- Sin esquema nuevo, sin permiso nuevo: lo ve quien ya ve el arqueo.

## 4. Criterios de aceptación
1. Un arqueo reabierto muestra, en su detalle, TODAS sus reaperturas: quién, cuándo y el motivo.
2. Mientras esté reabierto (estado abierto), el arqueo activo lo indica con el mismo dato.
3. Un arqueo nunca reabierto no muestra nada nuevo.
4. Tras volver a cerrarlo, las reaperturas siguen visibles en el detalle; el listado marca el día como «Reabierto».
5. Tests de componente y de API (campo en el historial); `tsc`, PHPStan y suites en verde.

## 5. Decisiones de Luis (2026-10-30)
1. **Dónde se muestra**: detalle del día, arqueo activo reabierto **y una marca en el listado del historial** (`history()` suma `reabierto`). ✅
2. **Quién ve el motivo**: **todos los que ven el arqueo**. ✅
3. **Cuántas reaperturas**: **todas** (no solo la última). Se leen de la auditoría; sin tabla nueva. ✅
4. **Entrega**: 1 PR de backend (reaperturas en el detalle y marca en el historial) y 1 de frontend — propuesta, no se preguntó.

## 6. Riesgos
Mínimos: es solo presentación sobre datos que ya existen. La lectura de la auditoría es por una sola entidad y acción (indexada por `entity_id`), sin costo apreciable. El campo nuevo del historial no cambia ningún cálculo contable.

## 7. No-alcance
Cambiar quién puede reabrir, el límite de un cierre por día, o reabrir días anteriores sin bloquear los siguientes. No hay tabla nueva: el historial sale de la auditoría.
