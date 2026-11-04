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
| [Ventas & Diseño: clientes, arquitectos, catálogo, calendario, Inicio y reportes](ventas-diseno.md) | Ventas & Diseño | verificado (10 pasos: acceso por rol, clientes y contactos, arquitectos y asociados, alcance Mías/Equipo, catálogo con PDF y ficha, calendario, Inicio/tablero/comisiones/proyectos, ajustes); la cotización y el pipeline siguen en la cadena venta a cobro; el CRM propio está en su propio catálogo |
| [CRM propio: proyectos, actividades, contactos, documentos y reportes](crm.md) | CRM | verificado (8 pasos: acceso por rol, alta y alcance por asignación, etapas, actividades y recordatorios, contactos, documentos con versiones y enlace público, tablero/reportes, ajustes y baja) |
| [Compras: órdenes, pagos, cambio de monto, reclamos y sustituciones](compras-ordenes.md) | Compras | verificado; pendiente: logística (vista del Líder de Operaciones) y familias de producto (11b); Zona Libre ya no existe |
| [Bodega: ajustes, reubicaciones, conteo general y devoluciones de clientes](bodega-inventario.md) | Bodega | verificado; mapeo de asistentes y llegada de inventario en [su propio catálogo](bodega-asistentes-llegadas.md) |
| [Admin & Contab: notas de crédito y anulaciones](admin-contab-notas-y-anulaciones.md) | Admin & Contab | verificado (notas de crédito, anulación completa, anulación de cobro); pendiente: compensaciones, abonos, incobrables, comisiones, caja chica, arqueo, tesorería (11d) |
| [Admin & Contab: compensaciones, incobrables, caja chica y arqueo](admin-contab-cierres.md) | Admin & Contab | verificado; pendiente: tesorería y movimientos bancarios, reportes (11d) |
| [Admin & Contab: comisiones externas (arquitectos)](admin-contab-comisiones-externas.md) | Admin & Contab ← Ventas & Diseño | verificado (generación «todo o nada», datos fiscales, cambio de porcentaje, pago); pendiente: comisión de cartera, reportes (11d) |
| [Admin & Contab: tesorería y movimientos bancarios](admin-contab-tesoreria.md) | Admin & Contab | verificado (comisión sin cuenta de pago, asignar cuenta, desactivar cuenta y saldo de bancos, más CRUD de cuentas y listado); pendiente: nada |
| [Admin & Contab: comisión de cartera +90 días](admin-contab-comision-cartera.md) | Admin & Contab | verificado (permisos, reporte, CRUD de tramos; el atraso >90 se verificó con una factura retrocedida en la base); pendiente: reportes restantes (11d) |
| [Admin & Contab: reportes e Inicio](admin-contab-reportes.md) | Admin & Contab | verificado (por cliente, libro de facturas, series, notas de crédito, Inicio y permisos) |
| [Bodega: ubicaciones, rutas del día y Kardex](bodega-ubicaciones-rutas-kardex.md) | Bodega | verificado; Zona Libre de Bodega ya no existe (retirada en la Fase 14f-1); pendiente: mapeo de asistentes, confirmación de llegada de inventario |
| [Bodega: asistentes y llegadas](bodega-asistentes-llegadas.md) | Bodega | verificado (mapeo vendedor→asistente, efecto en pedidos nuevos, carga por asistente y confirmación de llegada física en dos pasos); sin defectos |
| [Compras: reposición, proveedores y agencias de liquidación](compras-reposicion-proveedores.md) | Compras ← Bodega | verificado (faltante → reposición → compra → recepción → liberación, alta y validaciones de proveedores, agencias y contactos) |
| [Compras: familias de producto](compras-familias.md) | Compras | verificado (alta, listado y detalle con y sin costos, asignación de productos y generar compra por proveedor); sin defectos |
| [Servicios: tickets](servicios-tickets.md) | Servicios | verificado (instalación, garantía con compuertas de cierre, cotización de servicio, cancelación); informe de inspección y documentos en [su propio catálogo](servicios-informe-inspeccion.md) |
| [MFA y recuperación de clave](mfa-recuperacion-clave.md) | Auth (transversal) | verificado (olvidé mi contraseña, activar/usar/reiniciar el MFA, modo global y topes); pendiente: pantalla y correo real |
| [Servicios: técnicos, herramientas e insumos](servicios-tecnicos-herramientas.md) | Servicios | verificado (técnicos externos e internos, herramientas con su Kardex, insumos, SLA y permisos); informe de inspección y documentos en [su propio catálogo](servicios-informe-inspeccion.md) |
| [Servicios: hoja de reclamo y reportes](servicios-reclamo-reportes.md) | Servicios | verificado (hoja de reclamo, cierre del reclamo, PDF en blanco, siete reportes y biblioteca); informe de inspección y documentos en [su propio catálogo](servicios-informe-inspeccion.md) |
| [Servicios: informe de inspección y cotización](servicios-informe-inspeccion.md) | Servicios | verificado (campos por tipo, guardado, fotos, archivo subido, informe móvil con firma, PDF y documento de cotización); sin defectos |
| [Transversales: usuarios, acceso, permisos, «Ver como usuario», niveles y notificaciones](transversales.md) | varios | verificado; pendiente: perfil, departamentos, roles/módulos, reglas de aprobación y de notificación, ajustes globales, IA (11f) |
| [Transversales: administración](transversales-administracion.md) | Plataforma | verificado (departamentos, roles propios y visibilidad, reglas de aprobación de compras, reglas de notificación, ajustes globales, perfil de empresa e IA); sin defectos |

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
