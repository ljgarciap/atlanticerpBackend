# Pedidos gemelos de ICG — carga, relleno y vista de validación

Contexto: ICG se cerró el 05/10/2026 y los pedidos pendientes por facturar existen en AtlanticERP como **pedidos gemelos** (`bodega.orders.origin = 'icg_legacy'`). Spec: `atlanticerp/docs/specs/icg-pedidos-gemelos.md` y `icg-pedidos-gemelos-vista-validacion.md`. Todo `--apply` en test/prod lo autoriza Luis.

## 1. Carga (`legacy:load-pedidos-gemelos`)
```
php artisan legacy:load-pedidos-gemelos --tenant=atlantic --excel=legacy/pedidos-atlantic.xlsx --tolerancia=1.00            # dry-run (escribe un CSV de revisión)
php artisan legacy:load-pedidos-gemelos ... --apply --operador=<email> --esperado=<huella> --confirmar="APLICAR <entorno> atlantic"
```
Idempotente por (serie, número). Cada corrida con `--apply` guarda **un evento de auditoría** `legacy.cargar_pedidos_gemelos` con la huella, los totales del Excel por hoja y los omitidos con su motivo; la vista de validación lee el último. Cada gemelo guarda `icg_hoja` (hoja del Excel) e `icg_vendedor`.

## 2. Relleno de gemelos cargados antes de las columnas (`legacy:backfill-pedidos-gemelos-hoja`)
Para gemelos cargados sin `icg_hoja`/`icg_vendedor` (dev, por ejemplo). Solo rellena campos NULOS desde el mismo Excel; nunca toca importes, etapas, clientes ni líneas, y deja el informe de la carga.
```
php artisan legacy:backfill-pedidos-gemelos-hoja --tenant=atlantic --excel=legacy/pedidos-atlantic.xlsx --tolerancia=1.00           # dry-run
php artisan legacy:backfill-pedidos-gemelos-hoja ... --apply --operador="<nombre>" --esperado=<huella> --confirmar="APLICAR <entorno> atlantic"
```
Reversa: el evento guarda en `metadata.completados` el id de cada pedido y los campos rellenados (todos estaban en NULL): `update bodega.orders set icg_hoja = NULL, icg_vendedor = NULL where id in (...)` y borrar el evento si se quiere volver a «sin informe».

## 3. Vista de validación y «Facturar saldo»
Admin&Cont → Reportes → «Pedidos gemelos de ICG» (`/admin-contab/reportes/pedidos-gemelos`), solo lectura: resumen contra el Excel por hoja, filas con pendiente/facturado/saldo, advertencias y Excel descargable (auditado como `admin_contab.pedidos_gemelos.exported`). «Facturar saldo» abre Facturación con el pedido; un gemelo se factura **sin importar la etapa de Bodega** (decisión de Luis 2026-10-15), con las demás guardas intactas: monto ≤ saldo, solo líneas pendientes (L1), cliente con RUC/DV válido y permiso de facturar. Antes de la primera factura en prod, el contador debe confirmar el riesgo de facturar mercancía que puede no haber salido.
