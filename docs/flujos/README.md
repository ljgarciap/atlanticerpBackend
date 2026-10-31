# Catálogo de flujos — cómo funciona hoy

Fase 11 (`docs/specs/fase11-flujos-de-negocio-local.md`). Describe los procesos de negocio **tal como se comportan ahora**, para poder decidir qué cambiar o mejorar. No es una especificación de lo deseado.

## Cómo leerlo
Cada afirmación lleva una marca:
- **[E]** verificado **ejecutándolo** contra el entorno local (API real, cuentas demo por rol, 2026-10-28).
- **[C]** leído del código, no ejecutado.
- **[M]** dicho por un manual heredado (`docs/manuales-tecnicos/`) y no contrastado.

Cada flujo cierra con **Candidatos a cambio o mejora**: huecos, reglas rígidas, pasos manuales o deuda heredada. Son observaciones; **ninguna se implementa sin decisión**.

## Índice
| Flujo | Módulos | Estado del catálogo |
|---|---|---|
| [Venta a cobro](cadena-venta-a-cobro.md) | Ventas & Diseño → Compras → Bodega → Admin & Contab | verificado de punta a punta |
| Ventas & Diseño (clientes, arquitectos, comisiones, calendario, catálogo) | Ventas & Diseño, CRM | pendiente (11a) |
| [Compras: órdenes, pagos, cambio de monto, reclamos y sustituciones](compras-ordenes.md) | Compras | verificado; pendiente: logística, reposición, Zona Libre, agencias de liquidación, familias, proveedores (11b) |
| [Bodega: ajustes, reubicaciones, conteo general y devoluciones de clientes](bodega-inventario.md) | Bodega | verificado; pendiente: kardex y reportes en detalle, rutas del día, ubicaciones, Zona Libre de Bodega, mapeo de asistentes (11c) |
| [Admin & Contab: notas de crédito y anulaciones](admin-contab-notas-y-anulaciones.md) | Admin & Contab | verificado (notas de crédito, anulación completa, anulación de cobro); pendiente: compensaciones, abonos, incobrables, comisiones, caja chica, arqueo, tesorería (11d) |
| [Admin & Contab: compensaciones, incobrables, caja chica y arqueo](admin-contab-cierres.md) | Admin & Contab | verificado; pendiente: tesorería y movimientos bancarios, reportes (11d) |
| [Admin & Contab: comisiones externas (arquitectos)](admin-contab-comisiones-externas.md) | Admin & Contab ← Ventas & Diseño | verificado (generación «todo o nada», datos fiscales, cambio de porcentaje, pago); pendiente: comisión de cartera, reportes (11d) |
| [Servicios: tickets](servicios-tickets.md) | Servicios | verificado (instalación, garantía con compuertas de cierre, cotización de servicio, cancelación); pendiente: técnicos externos, herramientas, insumos, hoja de reclamo, reportes (11e) |
| [Transversales: usuarios, acceso, permisos, «Ver como usuario», niveles y notificaciones](transversales.md) | varios | verificado; pendiente: MFA, recuperación de clave por correo, perfil, departamentos, roles/módulos, reglas de aprobación y de notificación, ajustes globales, IA (11f) |

## Cierre
[Lista priorizada de candidatos](candidatos-priorizados.md): qué conviene convertir en fases, con impacto y esfuerzo.

## Prerrequisitos del entorno local (hallazgos de esta fase)
Sin esto los flujos no corren; ninguno está en el seed demo.
1. **Archivos**: `STORAGE_DRIVER=local` en `.env` (los archivos del pipeline, guías y adjuntos van a `s3-private`/`s3-public`; sin credenciales de S3 toda subida fallaba con 500). [E]
2. **Cobros**: no requiere ningún comando previo: desde la Fase 15a el concepto de cobros (con o sin factura, `POST /admin-contab/payments/cobro`) es el único. [E]
3. **Cuenta bancaria**: ninguna viene sembrada (Fase 4a); sin una no se registra un cobro por transferencia. [E]
4. **Existencias**: los productos del demo tienen stock 0; el picking exige saldo (ver Compras → ingreso). [E]
5. **Demo escaso**: 3 cotizaciones sin líneas, 0 cobros, 0 notas de crédito, 1 orden de compra. [E]

## Datos de prueba
Los flujos ejecutables crean sus propios datos con prefijo «[FLUJO]» y no modifican el demo.
