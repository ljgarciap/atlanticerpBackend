# Manuales técnicos por módulo

Un manual por módulo, escrito para que un desarrollador que no conoce el código pueda entenderlo y modificarlo con seguridad. Cubre backend y frontend.

## Por dónde empezar

| Si necesitas… | Ve a |
|---|---|
| Cómo se vende: tarjetas, etapas, cotizaciones, clientes, catálogo | [Ventas & Diseño](ventas-diseno.md) |
| Qué hace (y qué no hace) el módulo llamado CRM | [CRM](crm.md) |
| Tickets técnicos, informes, herramientas, insumos | [Servicios](servicios.md) |
| Comprar, recibir mercancía, reponer | [Compras](compras.md) |
| Pedidos, despachos, inventario por bodega, kardex | [Bodega](bodega.md) |
| Facturar, cobrar, notas de crédito, comisiones, caja | [Admin & Contabilidad](admin-contabilidad.md) |
| Factura electrónica, PAC, RUC, ITBMS en documentos fiscales | [Factura Electrónica](factura-electronica.md) |
| Indicadores ejecutivos y aprobaciones | [Gerencia](gerencia.md) |
| Widget de soporte | [Soporte](soporte.md) |

## Cómo se conectan los módulos

```
Ventas & Diseño ──(pedido aprobado)──▶ Bodega ──(entrega despachada)──▶ Admin & Contabilidad ──▶ Factura Electrónica
      │                                  ▲                                      └──▶ comisiones, caja, reportes
      │                                  │
      │                  Compras ──(recepción de mercancía)
      └──(proyecto)──▶ Servicios ──(insumos: Reserva Servicios)──▶ Bodega
Gerencia lee de todos · CRM y Soporte son periféricos
```

Cada manual lleva arriba y abajo una barra de navegación (anterior, siguiente, índice) y la lista de módulos relacionados.

## Los manuales

| Módulo | Qué cubre | Cobertura del inventario |
|---|---|---|
| [Ventas & Diseño](ventas-diseno.md) | CRM comercial real: Pipeline, cotizaciones, CotizaYA, clientes, catálogo, reportes y comisión de vendedores. | 360/360 (100 %) el 2026-10-12 |
| [CRM](crm.md) | Módulo `App\Modules\CRM` (no es el CRM visible): proyectos, actividades, documentos compartidos y reglas de notificación. | 126/126 (100 %) el 2026-10-12 |
| [Servicios](servicios.md) | Tickets de instalación, garantía, reclamo y retrofit; informes, cotizaciones, técnicos, herramientas e insumos. | 290/290 (100 %) el 2026-10-12 |
| [Compras](compras.md) | Órdenes de compra, proveedores, recepción de mercancía, reposiciones e inventario. | 253/253 (100 %) el 2026-10-12 |
| [Bodega](bodega.md) | Pedidos, despachos, picking, devoluciones, inventario por bodega y kardex. | 273/273 (100 %) el 2026-10-12 |
| [Admin & Contabilidad](admin-contabilidad.md) | Facturación, cobros, abonos, notas de crédito, comisiones, caja, caja chica y reportes administrativos. | 472/472 (100 %) el 2026-10-12 |
| [Factura Electrónica](factura-electronica.md) | Certificación de facturas y notas ante la DGI vía PAC (MSL, plan B Digifact) y validación de RUC. | 105/105 (100 %) el 2026-10-12 |
| [Gerencia](gerencia.md) | Tablero ejecutivo que agrega datos de los demás módulos y bandeja de aprobaciones. | 27/27 (100 %) el 2026-10-12 |
| [Soporte](soporte.md) | Integración con el sistema de tickets de soporte (widget embebido). | 7/7 (100 %) el 2026-10-12 |

## Plantilla (14 secciones)

1. Propósito y alcance · 2. Mapa del código · 3. Modelo de datos · 4. Reglas de negocio y parámetros · 5. Flujos principales · 6. API · 7. Permisos, roles y acceso · 8. Frontend · 9. Eventos, jobs e integraciones · 10. Configuración y datos semilla · 11. Pruebas · 12. Recetas para modificar el módulo · 13. Problemas conocidos y deuda · 14. Glosario · 11. Pruebas · 12. Recetas para modificar · 13. Problemas conocidos y deuda · 14. Glosario.

## Cómo se garantiza que no falta nada

`python3 scripts/manual-coverage.py <modulo> [--frontend <ruta al frontend>]` inventaría el código real (controladores, Form Requests, modelos, servicios, jobs, eventos, correos, tablas del ERD, rutas del OpenAPI, frontend y pruebas) y comprueba que el manual nombre cada elemento. Sale con código 1 si falta algo.

- Mide **presencia**, no calidad ni corrección: que un nombre aparezca no prueba que lo escrito sea cierto.
- La corrección se valida por muestreo, contrastando afirmaciones contra el código (Senior Review). El registro del piloto está en `docs/reviews/manual-tecnico-compras-20261012.md`.
- Para un módulo nuevo, añade su entrada al diccionario `MODULES` del script.

## Cómo se mantiene al día

- Todo cambio que toque rutas, tablas, estados, permisos, eventos, jobs o pantallas del módulo actualiza su manual **en el mismo cambio**.
- Antes de documentar o diagnosticar el frontend, compara el clon local con `origin/dev`: puede estar desfasado.
- La tabla de rutas del manual se genera con `python3 scripts/manual-routes-table.py <prefijo>` (usa `php artisan route:list --json` del stack local).
- Cada manual declara en su cabecera los commits de backend y frontend en que se basa.
