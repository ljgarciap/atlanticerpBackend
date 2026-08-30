# Admin&Cont (SCRUM-331) — Plan de batches del cuerpo principal de la épica

Arquitecto, 2026-08-19. Cubre los 148 tickets hijos directos de SCRUM-331 (REQ-426→592), una vez
cerrado el grupo "Configuración" de SCRUM-775 (Gestión de Usuarios EN PAUSA — ver
`memory/project_admincont_scrum331_batch_plan.md` en la memoria del proyecto —, Configuración
Fiscal y Datos de la Empresa ya en QA).

Inventario verificado ticket por ticket contra Jira (labels reales, no asumidas) — 148 exactos:

| Área funcional | Tickets | REQ | Cantidad |
|---|---|---|---|
| Cuentas Bancarias | SCRUM-607→611 | 530→534 | 5 |
| Facturación | SCRUM-513→528 | 436→451 | 16 |
| Cobros | SCRUM-539→552 | 462→475 | 14 |
| Estado de Cuenta | SCRUM-529→538 | 452→461 | 10 |
| Notas Crédito y Devoluciones | SCRUM-553→574 | 476→497 | 22 |
| Comisiones Internas | SCRUM-575→584 | 498→507 | 10 |
| Comisiones Externas | SCRUM-585→596 | 508→519 | 12 |
| Arqueo / Flujo de Caja | SCRUM-597→606 | 520→529 | 10 |
| Caja Chica | SCRUM-612→623 | 535→546 | 12 |
| Reportes (nav) | SCRUM-643→650 | 566→573 | 8 |
| Reporte Mensual Cliente | SCRUM-651→655 | 574→578 | 5 |
| Reporte Mensual Acumulado | SCRUM-656→660 | 579→583 | 5 |
| Reporte Libro de Facturas | SCRUM-661→664 | 584→587 | 4 |
| Reporte Ventas x Medio de Pago | SCRUM-665→669 | 588→592 | 5 |
| Home (dashboard) | SCRUM-503→512 | 426→435 | 10 |
| **Total** | | | **148** |

> ⚠️ **Nota de higiene de Jira:** SCRUM-571 (REQ-494, "Aprobar o rechazar una nota pendiente") no
> tiene ningún label puesto (`labels: []`) — confirmado por contenido y parent (SCRUM-331) que
> pertenece a Notas Crédito y Devoluciones, entre REQ-493 y REQ-495. No bloquea nada, pero conviene
> que alguien le agregue los labels `Admin&Contabilidad`/`Notas_Credito_Devoluciones` en Jira para
> que quede consistente con el resto.

## Orden de batches — por qué este orden

No es el orden numérico de REQ ni el orden del mockup — es el orden de **dependencia de datos
real**, mismo criterio que Servicios Fase 4 (fundacional primero, agregador/dashboard al final):

1. **Cuentas Bancarias primero** — catálogo referenciado por Cobros (cuenta destino), Comisiones
   (cuenta de pago) y Caja Chica (forma de pago). Sin esto, esas 3 áreas no tienen contra qué
   apuntar.
2. **Facturación** — genera las facturas de las que dependen Cobros (facturas abiertas),
   Notas Crédito (factura de origen) y Estado de Cuenta (qué se le debe a cada cliente).
3. **Cobros** — depende de Facturación + Cuentas Bancarias.
4. **Estado de Cuenta** — vista de solo lectura que cruza Facturación + Cobros.
5. **Notas Crédito y Devoluciones** — depende de Facturación (factura de origen) y afecta el saldo
   que ve Cobros/Estado de Cuenta. El grupo más grande (22 tickets, incluye 2 subtipos completos:
   Anulación completa y Corrección de datos).
6. **Comisiones Internas** — REQ-501 dice explícito "estado del pedido derivado automáticamente
   desde Cobros", no se puede construir antes.
7. **Comisiones Externas** — mismo motivo, depende de Cobros (REQ-512: "cobro del cliente determina
   el estado de la comisión").
8. **Arqueo / Flujo de Caja** — concilia cobros + notas de crédito del día, depende de ambos.
9. **Caja Chica** — funcionalmente el más independiente de todos (gastos de empleados, no depende
   de facturación/cobros), pero después de Cuentas Bancarias por la forma de pago.
10. **Reportes (los 4 sub-reportes + home de navegación)** — todos son de solo lectura sobre datos
    de Facturación/Cobros/Notas/Comisiones ya existentes, no pueden construirse antes.
11. **Home (dashboard) al final** — agrega literalmente todo lo anterior (total cobrado, cartera,
    comisiones por pagar, arqueo, notas de crédito) en un solo panel. Es el que más se rompería si
    se construyera primero contra datos que todavía no existen.

## Batches (≤6 tickets cada uno, mismo criterio que Servicios Fase 4)

### Cuentas Bancarias
- **Batch 1** — SCRUM-607→611 (REQ-530→534, 5): alta de cuenta, tabs Todas/cada cuenta/Eliminadas, detalle eliminar/reactivar, movimientos, asignar cuenta a comisión sin cuenta.

### Facturación
- **Batch 2** — SCRUM-513→518 (REQ-436→441, 6): encabezado+crear factura, indicadores, tabs cartera cobrable/incobrable, toggle vista, cliente clicable→Estado de Cuenta, generar factura+validación de requisitos.
- **Batch 3** — SCRUM-519→523 (REQ-442→446, 5): saldo a favor al generar, vista previa antes de confirmar, flujo guiado completo, filtros/búsqueda, exportar PDF/Excel.
- **Batch 4** — SCRUM-524→528 (REQ-447→451, 5): modal detalle/trazabilidad, incobrable+aprobación Mark, vista previa/impresión fiscal+estado Anulada, antigüedad de cartera, botón registrar cobro.

### Cobros
- **Batch 5** — SCRUM-539→544 (REQ-462→467, 6): encabezado+registrar cobro, indicadores, formulario alta (cliente+facturas abiertas), saldo a favor al registrar, método+cuenta bancaria auto-selección, campos condicionales+validaciones.
- **Batch 6** — SCRUM-545→548 (REQ-468→471, 4): comprobante de pago (carga), recibo+estado Confirmado/Esperando, historial filtros/búsqueda, modal detalle de cobro.
- **Batch 7** — SCRUM-549→552 (REQ-472→475, 4): ver comprobante adjunto, ver/imprimir recibo formal, apertura automática desde Facturación, marcar como confirmado (bancario manual).

### Estado de Cuenta
- **Batch 8** — SCRUM-529→533 (REQ-452→456, 5): encabezado+cierre, buscar estado de cuenta, estado vacío inicial, encabezado de resultado, descargar/imprimir.
- **Batch 9** — SCRUM-534→538 (REQ-457→461, 5): tarjeta de saldo, bloque de datos de pago, nota automática de contexto, tabla de movimientos, apertura automática desde Facturación.

### Notas Crédito y Devoluciones (grupo más grande — 22 tickets)
- **Batch 10** — SCRUM-553→558 (REQ-476→481, 6): encabezado+nueva nota, indicadores del mes, selección cliente/tipo (flujo manual), Anulación completa (subtipo+regreso mercancía), Anulación completa (Corrección de datos: motivo/ITBMS/fecha), devolución iniciada desde Bodega.
- **Batch 11** — SCRUM-559→564 (REQ-482→487, 6): banners informativos, selección de factura de origen, auto-aplicación al saldo+resolución de excedente, monto/desglose ITBMS/motivo/fecha, avisos automáticos (notificación cliente+reducción comisión), comprobante de soporte obligatorio.
- **Batch 12** — SCRUM-565→570 (REQ-488→493, 6): validaciones de registro, revisión previa+vista previa de factura nueva (Corrección de datos), aprobación diferida >$5,000, cola de devoluciones confirmadas por Bodega, historial filtros/búsqueda, modal de detalle.
- **Batch 13** — SCRUM-571→574 (REQ-494→497, 4): aprobar/rechazar nota pendiente (exclusivo Mark), ver comprobante de soporte adjunto, ver documento (Nota de Crédito formal), ver factura relacionada (trazabilidad).

### Comisiones Internas (vendedores)
- **Batch 14** — SCRUM-575→579 (REQ-498→502, 5): encabezado+tabla escalonada+exportar, indicadores+aviso de pago automático, filtro de mes+vendedor, estado del pedido derivado de Cobros, cálculo de comisión escalonada.
- **Batch 15** — SCRUM-580→584 (REQ-503→507, 5): notas de crédito reducen base de comisión, arrastre de pedidos de meses cerrados sin cobrar, tabla de vendedores expandir/colapsar, proyectos compartidos entre vendedores, estado de cuenta de comisiones.

### Comisiones Externas (arquitectos)
- **Batch 16** — SCRUM-585→590 (REQ-508→513, 6): encabezado+indicadores, filtros, datos fiscales del arquitecto, cálculo de comisión/impuesto/total según régimen, cobro del cliente determina estado, cuenta de cobro del arquitecto.
- **Batch 17** — SCRUM-591→596 (REQ-514→519, 6): comprobante de retención (régimen 50%), marcar comisión como pagada, editar %+aprobación Mark, cuenta de pago editable, recordar al arquitecto, tabla expandir+modal de detalle.

### Arqueo / Flujo de Caja
- **Batch 18** — SCRUM-597→601 (REQ-520→524, 5): encabezado+indicadores de liquidez, vista proyectada, toggle vista proyectado/real+ventana de tiempo, vista real histórico, arqueo del día.
- **Batch 19** — SCRUM-602→606 (REQ-525→529, 5): ajustes/retención (constancia del cliente), cerrar arqueo del día, bloqueo secuencial de arqueos atrasados, historial+aprobación de Gerencia (Mark), ver detalle/descargar resumen.

### Caja Chica
- **Batch 20** — SCRUM-612→617 (REQ-535→540, 6): registrar gastos multi-línea, tabs Pendientes/Reportes/Rechazados, pendientes de reporte agrupado, generar reporte (2 pasos), reportes generados+detalle, aprobar reporte completo (exclusivo Mark).
- **Batch 21** — SCRUM-618→623 (REQ-541→546, 6): rechazar línea/reporte (exclusivo Mark), regla de 2 intentos, panel rechazados permanentes, reabrir línea rechazada, detalle de línea (modal unificado), ver soporte+descargar reporte formal.

### Reportes
- **Batch 22** — SCRUM-643→647 (REQ-566→570, 5): home de Reportes — encabezado+selector de período, tarjeta comisión gestión cartera Felix, tarjetas cartera por cobrar/cobrado +90 días, tarjeta Ventas, tarjeta Arqueo de Caja. CERRADO 2026-08-27.
- **Batch 23** — SCRUM-648→650 (REQ-571→573, 3): tarjeta Comisiones (Internas vs Externas), tarjeta Notas de Crédito y Devoluciones, accesos de navegación a los 4 sub-reportes. **Fusionado con los Batches 24-27 de abajo** (decisión de Luis 2026-08-27): SCRUM-650 exigía que las 4 pantallas destino existieran de verdad antes de pasar Pre-QA — se implementaron los 22 tickets (SCRUM-648→669) en la misma sesión, en 4 grupos secuenciales (Comisiones/Notas → Mensual por Cliente/Acumulado → Libro de Facturas/Ventas por medio de pago → accesos de navegación, al final). CERRADO 2026-08-27, ver `memory/project_admincont_batch23_reportes_completo_cerrado.md`.
- **Batch 24** — SCRUM-651→655 (REQ-574→578, 5): Reporte Mensual por Cliente completo. Ver Batch 23 — cerrado junto con él.
- **Batch 25** — SCRUM-656→660 (REQ-579→583, 5): Reporte Mensual Acumulado completo. Ver Batch 23 — cerrado junto con él.
- **Batch 26** — SCRUM-661→664 (REQ-584→587, 4): Reporte Libro de Facturas completo. Ver Batch 23 — cerrado junto con él.
- **Batch 27** — SCRUM-665→669 (REQ-588→592, 5): Reporte de Ventas por Medio de Pago completo — 9 métodos de pago reales, no los 7 del texto original del REQ (confirmado con Luis, ver Batch 23). Cerrado junto con él.

### Home (dashboard) — último
- **Batch 28** — SCRUM-503→507 (REQ-426→430, 5): encabezado saludo+resumen del día, total cobrado, cuentas al día, cartera por cobrar, ventas de ayer. **Fusionado con el Batch 29** (decisión de Luis, mismo criterio que el Batch 23: es una sola pantalla del mockup con cálculos compartidos entre ambos batches nominales) — los 10 tickets se implementaron en la misma sesión, en 5 grupos secuenciales (Resumen del mes → Mi calendario → Pendientes → Vencidos y por vencer/Antigüedad → encabezado+ensamblaje final). CERRADO 2026-08-27, Pre-QA/Visual Review fusionado sin bloqueantes, ver `memory/project_admincont_batch_home_scrum503_512_cerrado.md`. **Cierra la épica Admin&Cont completa — 167/167 tickets.**
- **Batch 29** — SCRUM-508→512 (REQ-431→435, 5): comisiones internas/externas por pagar, mi calendario, pendientes, vencidos y por vencer, antigüedad de cuentas por cobrar. Ver Batch 28 — cerrado junto con él.

## Total: 29 batches, 148 tickets, promedio 5.1 tickets/batch

Mismo flujo que los dos batches ya cerrados de Admin&Cont (Configuración Fiscal, Datos de la
Empresa): Analista/Arquitecto revisan las specs completas del batch antes de dispatchar → Backend
Dev + Frontend Dev en paralelo (forks compartiendo el contrato de API) → Senior Review + Visual
Review/Pre-QA fusionado (suite completa, mockup si existe) → push a `dev` con aprobación explícita
→ deploy manual (`infra/deploy-manual.sh`, CI/CD automático caído por cuota) → comentario Jira +
transición a QA por ticket.

**No arrancar un batch nuevo sin que Luis lo apruebe explícitamente** (mismo criterio que los 2
batches ya cerrados) — este documento es el plan, no una autorización para dispatchar en cadena.
