# Fase 14h — Mostrar quién reabrió un arqueo y el motivo

**Estado**: EN BORRADOR, pendiente de que Luis decida §5. Origen: «Abierto» de la Fase 13 (2.10): «la interfaz no muestra quién reabrió ni el motivo». No se implementa sin su «adelante».

## 1. Objetivo
Reabrir un arqueo cerrado es una acción sensible (solo el aprobador o su suplente, con motivo de al menos 10 caracteres). Hoy queda guardada y auditada, pero quien mira el arqueo no ve que fue reabierto ni por qué. Que la pantalla lo diga.

## 2. Hallazgos (medidos sobre `master`)
- **Se guarda** en `daily_cash_counts`: `reabierto_por`, `reabierto_at`, `reabierto_motivo`. Es la **última** reapertura; el historial completo está en `audit_logs` (acción `admin_contab.daily_cash_count.reopened`).
- **La API ya lo envía** en el detalle y en el arqueo activo (`DailyCashCountService::present()`): `reabierto_por` (nombre), `reabierto_at` e `reabierto_motivo`. El tipo del frontend ya los declara.
- **Ninguna pantalla los usa** (`ArqueoCajaPage`): el detalle de un día solo dice «Cerrado por…» y «Aprobado por…».
- **El listado de historial NO los trae** (`DailyCashCountService::history()` devuelve número, fecha, total, estado, quién cerró y quién aprobó).
- Un arqueo reabierto conserva su número al volver a cerrarse, así que no hay otra pista visible de que se reabrió.

## 3. Diseño propuesto
- **Detalle del día** (frontend, sin cambios de API): bloque «Reabierto por {nombre} el {fecha}» con el motivo, visible mientras exista la marca; usa los campos que ya llegan.
- **Arqueo activo reabierto** (frontend): banner ámbar sobre el arqueo abierto con la misma información, para que quien lo vuelva a cerrar sepa por qué está abierto.
- **Listado de historial** (backend + frontend): `history()` agrega `reabierto` (booleano) y el listado muestra una marca discreta «Reabierto» en la fila, con el motivo en el tooltip (decisión 1).
- Sin esquema nuevo, sin permiso nuevo: lo ve quien ya ve el arqueo.

## 4. Criterios de aceptación
1. Un arqueo reabierto muestra, en su detalle, quién lo reabrió, cuándo y el motivo.
2. Mientras esté reabierto (estado abierto), el arqueo activo lo indica con el mismo dato.
3. Un arqueo nunca reabierto no muestra nada nuevo.
4. Tras volver a cerrarlo, la última reapertura sigue visible en el detalle.
5. Tests de componente y de API (campo en el historial); `tsc`, PHPStan y suites en verde.

## 5. Decisiones para Luis
1. **Alcance de lo que se muestra**: solo el detalle y el arqueo activo (frontend), o también una marca en el listado del historial (agrega un campo a la API). Recomendado: **también el listado**: es donde Contabilidad revisa varios días y hoy no hay forma de saber cuáles se reabrieron.
2. **¿Quién ve el motivo?** Todos los que ven el arqueo, o solo el aprobador y su suplente. Recomendado: **todos los que ven el arqueo**: el motivo es parte de la trazabilidad y no contiene datos sensibles.
3. **Historial de reaperturas**: mostrar solo la última (lo que hay hoy) o listar todas (habría que leerlas de la auditoría o crear una tabla). Recomendado: **solo la última** por ahora; el historial completo sigue en la auditoría.
4. **Entrega**: 1 PR de backend (campo en el historial) y 1 de frontend — propuesta, no se preguntó.

## 6. Riesgos
Mínimos: es solo presentación sobre datos que ya existen. El campo nuevo del historial no cambia ningún cálculo contable.

## 7. No-alcance
Cambiar quién puede reabrir, el límite de un cierre por día, reabrir días anteriores sin bloquear los siguientes, o crear una tabla de historial de reaperturas.
