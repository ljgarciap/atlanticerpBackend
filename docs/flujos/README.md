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
| Compras (pagos, logística, reposición, reclamos, Zona Libre) | Compras | pendiente (11b) |
| Bodega (inventario, ajustes, devoluciones, rutas, ubicaciones) | Bodega | pendiente (11c) |
| Admin & Contab (notas de crédito, anulaciones, comisiones, caja chica, arqueo, tesorería) | Admin & Contab | pendiente (11d) |
| Servicios (tickets, técnicos, inspecciones, insumos) | Servicios | pendiente (11e) |
| Transversales (acceso, usuarios y permisos, notificaciones, catálogos, perfil de empresa) | varios | pendiente (11f) |

## Prerrequisitos del entorno local (hallazgos de esta fase)
Sin esto los flujos no corren; ninguno está en el seed demo.
1. **Archivos**: `STORAGE_DRIVER=local` en `.env` (los archivos del pipeline, guías y adjuntos van a `s3-private`/`s3-public`; sin credenciales de S3 toda subida fallaba con 500). [E]
2. **Cobros**: `php artisan cobros:v2 on` (por defecto apagado; con la bandera apagada `POST /admin-contab/payments/cobro` responde «todavía no está activo»). [E]
3. **Cuenta bancaria**: ninguna viene sembrada (Fase 4a); sin una no se registra un cobro por transferencia. [E]
4. **Existencias**: los productos del demo tienen stock 0; el picking exige saldo (ver Compras → ingreso). [E]
5. **Demo escaso**: 3 cotizaciones sin líneas, 0 cobros, 0 notas de crédito, 1 orden de compra. [E]

## Datos de prueba
Los flujos ejecutables crean sus propios datos con prefijo «[FLUJO]» y no modifican el demo.
