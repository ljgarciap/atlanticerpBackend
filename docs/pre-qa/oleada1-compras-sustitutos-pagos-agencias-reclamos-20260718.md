Feature: Oleada 1 completa (Agencias de Liquidación SCRUM-254→256, Pagos a Proveedores
SCRUM-250→253, Garantías y Reclamos SCRUM-257→261, Sustitutos sin IA SCRUM-248/249) — 14 tickets
Date: 2026-07-18
Pre-QA: pasada real contra `dev` local (Docker: nginx+laravel+postgres+redis+horizon), stack
completo — API real vía curl con JWT de usuarios reales seedeados (`management@atlantic.test`,
`designer@atlantic.test`), Postgres real (`atlantic_compras`), S3 real (`atlanticerp-dev`
bucket, comprobantes/fotos con presigned URLs reales), PDF real generado con dompdf y verificado
visualmente. Pantallas verificadas en navegador real (Chrome vía claude-in-chrome) tras detectar y
corregir que nginx servía un build de frontend desactualizado (`atlanticerp-frontend/dist/` no se
reconstruye solo — hay que correr `npm run build` para que los cambios de código lleguen a
`dev.atlanticerp.ai`-equivalente local; nota operativa, no bug de código).

---

## Rondas de esta pasada

**Ronda 1 (Senior Review, previa a Pre-QA):** 2 hallazgos encontrados leyendo código real, no solo
tests verdes — corregidos antes de arrancar Pre-QA:
1. `POST /compras/claims` gateado con `compras.edit` en vez de `compras.write` (inconsistente con
   el resto de recursos top-level del módulo).
2. `ReplacementRequestController::generateOrder()` no validaba `provider_id` nulo del producto
   original (`catalog_products.provider_id` es nullable) — causaba 500 sin capturar en vez de 422.

**Ronda 2 (Pre-QA, vía API real):** 1 hallazgo nuevo, no cubierto por ningún escenario literal de
los tickets — "camino de ruptura que el ticket no previó" (Paso 3 del protocolo):
3. Se puede **aprobar** un cambio de monto pendiente (REQ-189 RN7) **después** de que ya se
   registró un pago en el medio — la aprobación quedaba obsoleta pero se aplicaba igual, pudiendo
   dejar `total_amount` por debajo de `paid_amount`. `requestAmountChange()` ya bloqueaba pedir un
   cambio nuevo con pagos existentes, pero `approveAmountChange()` no revalidaba al momento de
   aprobar una solicitud que quedó pendiente de ANTES del primer pago. Reproducido con datos reales
   (orden con `pending_amount_change=999`, luego un pago de 10, luego intento de aprobar → antes
   del fix aplicaba igual dejando el estado inconsistente).
   **Fix:** `approveAmountChange()` ahora revalida pagos existentes al momento de aprobar, y
   descarta automáticamente el cambio obsoleto (422 explicando por qué) en vez de aplicarlo.

**Ronda 3 (Pre-QA, navegador real):** 1 hallazgo nuevo, puramente de frontend:
4. En el detalle de Agencia de Liquidación, la columna "Pedidos con esta agencia" mostraba
   literalmente `NEWORDER.LIQUIDATIONAGENCY` en vez de un texto traducido — clave de i18n
   inexistente, además apuntando al concepto equivocado (el picker de Nueva Orden, no el estado de
   liquidación de cada fila). No lo detectaba `tsc`/`vitest` porque i18next hace fallback silencioso
   a la clave cruda en vez de fallar. **Fix:** clave nueva `liquidationStatusHeader`, semánticamente
   correcta.

**Ronda 4 (re-verificación completa tras los 4 fixes):** pasada limpia. Detalle por grupo abajo.

---

## Agencias de Liquidación (SCRUM-254/255/256)

| Escenario | Resultado |
|---|---|
| Crear agencia | ✅ 201, campos correctos |
| Detalle recién creada (0 contactos) | ✅ |
| Agregar contacto | ✅ 201 |
| Quitar el único contacto (RN2) | ✅ 422, mensaje correcto, verificado en navegador (modal con error inline) |
| Agregar 2do contacto + quitar 1ro (ahora permitido) | ✅ 204 |
| Resumen — orden Zona Libre asignada, status `por_aprobar` | ✅ `pending_count=1, liquidated_count=0` |
| Orden avanza hasta `en_aduana` | ✅ resumen y detalle pasan a "liquidada" automáticamente, sin intervención manual — misma definición que Logística |
| Header "Estado de liquidación" en detalle | ✅ tras fix #4 |

## Pagos a Proveedores (SCRUM-250/251/252/253)

| Escenario | Resultado |
|---|---|
| Registrar pago sin comprobante | ✅ 422 "El campo proof es obligatorio" |
| Registrar pago que excede el saldo | ✅ 422 con el máximo real calculado |
| Pago parcial (60 de 100) | ✅ `payment_status=parcial`, `paid_amount=60` |
| Completar el pago (+40) | ✅ `payment_status=completo` |
| Pago adicional con saldo en 0 | ✅ 422 |
| Historial acumula cada registro por separado | ✅ `[60, 40]`, no se mezclan |
| Cambiar monto con pagos ya registrados (RN7) | ✅ 422 |
| "Enviar a pago" no bloquea el registro real | ✅ ambos flujos coexisten |
| Cambio de monto sin pagos → pendiente de Mark | ✅ `total_amount` no cambia hasta aprobar |
| No-Mark intenta aprobar | ✅ 403 |
| Mark aprueba (sin pagos en el medio) | ✅ `total_amount` se actualiza |
| **Aprobar cambio obsoleto (pago registrado en el medio)** | ✅ 422 tras fix #3, `total_amount` queda intacto, `pending_amount_change` se limpia |
| UI: panel de pagos en detalle de orden | ✅ verificado en navegador — estado/pagado/saldo/botones/historial |

## Garantías y Reclamos (SCRUM-257/258/259/260/261)

| Escenario | Resultado |
|---|---|
| Crear reclamo excediendo cantidad pedida | ✅ 422 con el máximo real |
| Crear reclamo repitiendo producto | ✅ 422 |
| Crear reclamo válido, con descripción y resolución esperada | ✅ 201, `code` autogenerado (R-1007), `total_amount` correcto |
| Crear sin permiso `compras.write` (solo lectura) | ✅ 403, tras fix #1 |
| Cambiar estado a "Resuelto" | ✅ bandera de atención se quita |
| Subir foto de evidencia | ✅ 201, URL de S3 real presignada |
| PDF real del reclamo | ✅ generado, verificado visualmente (contenido, marca, totales, resolución esperada — todo correcto) |
| REQ-198 alcance reducido | ✅ `service_tickets: []` siempre |
| UI: lista con 4 KPIs + chips + selector de estado en línea | ✅ verificado en navegador |
| UI: modal de detalle con línea/foto/PDF/link a orden | ✅ verificado en navegador |

## Sustitutos, sin IA (SCRUM-248/249)

| Escenario | Resultado |
|---|---|
| Listado sin filtro / filtrado por estado | ✅ |
| Generar orden de solicitud NO rechazada | ✅ 422 |
| Generar orden de producto sin proveedor asignado | ✅ 422 tras fix #2 (antes: 500) |
| Generar orden de solicitud rechazada válida | ✅ 200, `order_id` real, status `por_aprobar` |
| La orden generada usa el producto ORIGINAL, no el sustituto propuesto | ✅ verificado por API y visualmente en navegador (`Bombillo Original Visual`, no el sustituto) |
| Generar orden dos veces de la misma solicitud | ✅ 422 |
| UI: tabla con "Generar orden" solo en filas Rechazadas | ✅ verificado en navegador |

---

## Lo que sí funciona (sin hallazgos)

Todo lo listado arriba con ✅. Además: PHPStan Level 8 limpio en todo el batch, 914/914 tests
backend, 345/345 tests frontend, `tsc --noEmit` limpio — verificado después de cada fix, no solo
al final.

## Gaps de alcance ya documentados (no son hallazgos de Pre-QA, ya acordados con Luis)

- REQ-191 (Agencias): "situación de pago" sin RN documentadas — no construido, documentado en código.
- REQ-187 (Pagos): "5 indicadores" sin RN documentadas — no construidos, documentado en código.
- REQ-198 (Reclamos): alcance reducido acordado — `service_tickets` siempre vacío.

## Veredicto

✅ **Pasada limpia tras 3 vueltas de hallazgos reales (2 de Senior Review + 2 de Pre-QA), todos
corregidos y re-verificados con el checklist completo, no solo el punto que falló.** Los 14
tickets pasan a `QA`.
