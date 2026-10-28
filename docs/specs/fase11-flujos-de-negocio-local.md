# Spec — Fase 11: flujos de negocio completos en el entorno local

**Estado**: BORRADOR — decisiones de Luis pendientes (§5).
**Origen**: «lo que nos importa en primera instancia es probar el desarrollo local y ajustarlo antes de desplegarlo» (2026-10-28). El humo de las fases 6 a 9 ya existe; falta ejercitar las **cadenas de negocio** de punta a punta.

## 1. Hechos verificados (BD local de desarrollo, 2026-10-28)
| Tabla | Filas | Lectura |
|---|---|---|
| `ventas_diseno.quotes` / `quote_items` | 3 / **0** | cotizaciones sin ninguna línea: no se puede avanzar ninguna |
| `ventas_diseno.pipeline_cards` / `sales_projects` | 12 / 8 | alcanzan |
| `bodega.orders` / `order_items` | 5 / 5 | hay pedidos para picking |
| `admin_contab.invoices` / `payments` / `credit_notes` | 1 / **0** / **0** | sin cobros ni notas de crédito |
| `compras.purchase_orders` / `providers` | 1 / 3 | una sola OC; sin recepciones |
| `servicios.tickets` | 14 | alcanzan |
- Facturación: el proveedor por defecto es `none` (Fase 6b): se emite **documento interno**, sin transmitir a ninguna autoridad. Es el modo que se prueba en local.
- El recorrido por pantallas (5 roles, 44 pantallas) no encontró errores, pero con estos datos no ejercita ninguna transición.
- Cadena de venta, por endpoints reales: `POST ventas-diseno/quotes` → `…/parts` → `…/items` → `POST quotes/{id}/confirm` → `PATCH ventas-diseno/pipeline/{id}/stage` → (pedido en Bodega) `start-picking` → `complete-picking` → `dispatch` → `register-delivery` → `POST admin-contab/invoices` → `POST admin-contab/payments/cobro` → `PUT payments/{id}/confirm`.
- Cadena de compra: `POST compras/orders` → `PATCH …/approve` → `PATCH …/advance` → `POST compras/goods-receipts` → `…/lines/{id}/confirm` (sube el stock).
- Límites: nginx admite 10 conexiones simultáneas a la API (un solo worker de Playwright); los datos de facturas y cobros no se borran (inmutables por diseño).

## 2. Diseño propuesto
**11a — Flujos ejecutables** (`e2e/flujos-atlantic.spec.ts`, `npm run e2e:flujos`, 1 worker): cada flujo crea **sus propios datos** (prefijo «[FLUJO]» + marca de tiempo) por la API real con la cuenta de cada rol y verifica el estado en cada paso, con capturas de las pantallas clave en el navegador. No depende del demo ni lo modifica.
1. **Venta**: vendedor cotiza (2 líneas) → confirma → avanza el pipeline → Bodega arma, despacha y registra entrega → Contabilidad factura y registra el cobro → el estado de cuenta refleja saldo 0.
2. **Compra**: líder de compras crea OC → gerencia/aprobador aprueba → recepción de mercancía → el stock del almacén sube.
3. **Servicios**: ticket de instalación creado → asignado a un técnico → cierre.
4. **Permisos cruzados**: cada paso intentado con un rol que no corresponde devuelve 403 (p. ej. el vendedor no despacha, el técnico no factura).
**11b — Demo más completo** (opcional, según §5-2): líneas en las 3 cotizaciones, un cobro y una nota de crédito, una OC recibida, para que las pantallas de reportes y estados de cuenta no salgan vacías al recorrerlas a mano.
**11c — Ajustes**: todo hallazgo de los flujos se corrige en su propio PR pequeño (como el de familias), con test de regresión.

## 3. Fuera de alcance
Transmisión fiscal real (DGI/MSL/Digifact), correo saliente, AWS y despliegue, el adaptador de Colombia, rendimiento/carga.

## 4. Criterios de aceptación
1. `npm run e2e:flujos` corre los 4 flujos en verde contra el stack local y es repetible (cada corrida crea datos nuevos sin chocar con las anteriores).
2. Cada transición verifica el estado resultante, no solo el código HTTP.
3. Todo hallazgo queda en un informe (`docs/reviews/fase11-…`) con su PR de corrección y prueba de regresión.
4. Los textos de commits, PR y datos de prueba no incluyen nombres reales ni vocabulario de otro sector (contrato de neutralidad).

## 5. Decisiones de Luis (pendientes)
1. Enfoque: flujos que crean sus propios datos (recomendado) / demo más completo / ambos.
2. Cadenas incluidas: venta, compra, servicios, permisos cruzados.
3. Qué hacer con los datos que dejan los flujos en la BD local.
4. Entrega: PR por sub-fase.
