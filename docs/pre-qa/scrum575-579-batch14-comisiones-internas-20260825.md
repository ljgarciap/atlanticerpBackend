# Pre-QA + Visual Review fusionado — Batch 14 Comisiones Internas (SCRUM-575→579, REQ-498→502)

**Fecha:** 2026-08-25. Contra `dev.atlanticerp.ai` — backend `ac2a712` / frontend `c9d4084` al iniciar
la pasada; **backend `93cdff8` / frontend `2d9d9f5` desplegados y re-verificados en vivo** tras
deploy manual (`infra/deploy-manual.sh dev`, CI/CD automático sigue caído por el apagón de
billing de GitHub Actions) — ambos fixes confirmados funcionando en el entorno real, no solo en
el código. Ver "Re-verificación post-deploy" al final.

Mockup: `4E__Admin_Contabilidad_ComisionesInt.html` (adjunto SCRUM-575, id 11249). ADR:
`docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md`. Senior Review previo:
`docs/reviews/scrum575-579-batch14-comisiones-internas-20260825.md`.

## Veredicto por ticket

| Ticket | Veredicto |
|---|---|
| SCRUM-575 (REQ-498) | **LIMPIO — pasa a QA.** Encabezado, modal de tramos de solo lectura para no-Mark (re-verificado post-deploy: Felix ya no ve editar/eliminar/agregar tramo), exportar PDF/Excel respetando filtros (200 OK, confirma el fix del Senior Review previo) — todo verificado en vivo contra el código desplegado. |
| SCRUM-576 (REQ-499) | **LIMPIO — pasa a QA.** 4 tarjetas con los valores esperados, banner de pago automático independiente del filtro de mes (condicional a que haya "Por pagar" — no se pudo forzar ese escenario con datos reales de dev, cubierto por tests backend). |
| SCRUM-577 (REQ-500) | **LIMPIO — pasa a QA.** Flechas de mes + "Volver al mes actual" (aparece solo fuera del mes actual, comportamiento correcto no exigido literal por el mockup pero coherente con RN1) + filtro de vendedor, scoping Felix/Mark vs vendedor confirmado. |
| SCRUM-578 (REQ-501) | **LIMPIO — pasa a QA.** Estado pendiente_cobro/por_pagar/pagado visible y coherente con los datos reales de dev; tabla de detalle ahora muestra las 5 columnas de trazabilidad del mockup (re-verificado post-deploy). RN1-RN5 completos cubiertos por 23 tests backend (`AdminContInternalCommissionControllerTest`) — no se pudieron ejercitar los 5 escenarios completos con datos reales de dev.atlanticerp.ai en esta pasada (dato insuficiente para "pagado" en agosto 2026), aceptado como limitación operativa, no bloqueante. |
| SCRUM-579 (REQ-502) | **LIMPIO — pasa a QA.** % escalonado visible con badge "provisional" en mes en curso, valores coherentes con los 6 tramos seedeados. |

## Hallazgos encontrados y corregidos en esta misma pasada

### 1. CRÍTICO — tabla de detalle por pedido omitía 5 columnas del mockup

La fila expandida por vendedor (`ComisionesInternasPage.tsx`) solo mostraba Cliente/N° pedido/
Total pedido/Total cobrado/Monto com./Estado — el mockup (`milestone-table`) además muestra
Fecha pedido, N° factura, Fecha factura, Fecha cobro y Total facturado. El backend ya devolvía
los 5 campos (`CommissionOrder.fecha_pedido/numero_factura/fecha_factura/fecha_cobro_completo/
total_facturado`, confirmado con la respuesta real de `GET .../commissions/internal`) — el
frontend simplemente no los renderizaba. Sin esas columnas, Felix/Mark no pueden verificar por
qué un pedido tiene el estado que tiene sin salir de la pantalla — justo la trazabilidad que
REQ-501 busca dar. No es parte de ningún diferido documentado a Batch 15 (ADR §3).

**Fix:** `atlanticerp-frontend` commit `64d8d95` — agrega las 5 columnas, reusa `formatDateShort`
(`utils/dates.ts`, ya resuelve el gotcha de timezone de SCRUM-787), agrega labels es/en. Suite
completa de frontend corrida limpia (1506/1506 vitest, `tsc --noEmit` limpio, build de
producción limpio).

### 2. CRÍTICO — CRUD de tramos visible para Felix, 403 real al guardar

El modal "Tabla de comisión escalonada" gateaba los botones editar/eliminar/agregar tramo con
`user.modules.admin_contab.edit === true` — Felix tiene ese flag (no solo Mark), así que veía
los controles completos (confirmado en vivo con captura), pero `POST/PUT/DELETE
.../commissions/internal/tiers` está gateado por `mark_only` en el backend (routes/admin-contab.
php), exclusivo del `mark_approver_user_id` configurado. Felix hubiera intentado guardar un
cambio de tramo y recibido un 403 sin ningún fix visual — mismo patrón de bug ya documentado en
este proyecto (`feedback_disabled_button_before_data_loads_is_a_click_trap`, SCRUM-63). También
tensiona RN1 de SCRUM-575 ("de solo consulta — no se edita desde este modal") para cualquier rol
que no sea realmente Mark.

**Fix:** `atlanticerp-backend` commit `93cdff8` + `atlanticerp-frontend` commit `2d9d9f5`. El summary
(`GET .../commissions/internal`) ahora expone `puede_editar_tramos`, calculado server-side
(`current_user_id === mark_approver_user_id`, con bypass `superadmin.all`) — mismo patrón ya
establecido en `AdminContInvoiceController::detail()` para `puede_decidir_incobrable`. El
frontend usa ese campo en vez de decidir por su cuenta vía un flag de módulo genérico. 2 tests
de regresión agregados en frontend (Felix con `edit=true` no ve "Agregar tramo", Mark sí) + 1 en
backend (`test_summary_puede_editar_tramos_solo_para_mark`).

## Gate real corrido antes de cada push

- Backend: suite completa `infra/test.sh` (2577 tests, 10436 assertions) limpia + PHPStan Level
  8 completo (682 archivos) sin errores.
- Frontend: `npx vitest run` completo (133 archivos, 1506 tests) limpio + `tsc --noEmit` limpio +
  `npm run build` limpio.

## Re-verificación post-deploy (2026-08-25, misma sesión)

Deploy manual (`infra/deploy-manual.sh dev`) corrido por el Arquitecto — backend `93cdff8`,
frontend `2d9d9f5`, nginx recargado, `/up` y `/` responden 200. Re-verificado en vivo con
Playwright CLI, navegador real, sin caché (contexto nuevo por test, `waitUntil: 'networkidle'`):

1. **Columnas nuevas en la tabla de detalle** — Fecha pedido, Factura, Fecha factura, Fecha
   cobro y Total facturado, las 5, visibles en la fila expandida de un vendedor con datos reales
   (Idmar Hernandez, agosto 2026). Confirmado por texto de DOM y por captura.
2. **CRUD de tramos gateado a Mark** — Felix (`conta@atlantic.com.pa`) abre el modal "Tabla
   de comisión escalonada" y ya NO ve los íconos de editar/eliminar por fila ni "+ Agregar
   tramo" — modal 100% de solo lectura. Mark (`mbekhar@atlantic.com.pa`) abre el mismo
   modal y SÍ ve los 3 controles (editar/eliminar por tramo + agregar). Confirmado por texto de
   DOM y por captura, ambos roles en la misma sesión de verificación.

Ambos hallazgos quedan cerrados con el loop completo (hallazgo → fix → gate real → deploy →
re-verificación en el entorno real) — no solo "corregido en local".

Los 5 tickets pasan a `QA`.

## Lo que sí funciona (verificado en vivo contra dev.atlanticerp.ai, código ya desplegado)

- Sidebar → "Admin. & Contab." → grupo "Comisiones" → "Internas" navega correctamente a
  `/admin-contab/comisiones/internas` (rail colapsado por defecto, expande con la flecha
  superior — variante de layout aceptable vs. el subtabs-nav del mockup, mismo criterio ya
  aplicado en batches anteriores de Admin&Cont).
- 4 tarjetas de resumen con valores reales.
- Filtro de mes (flechas + "Volver al mes actual") y filtro de vendedor, con scoping correcto:
  Felix/Mark ven todos los vendedores + exportar; el vendedor ve solo su propia fila, sin
  selector de vendedor ni botón de exportar, pero sí puede abrir la tabla de tramos de solo
  lectura.
- Modal "Tabla de comisión escalonada" muestra los 6 tramos correctos (1.5%/2%/3%/4%/4.5%/5%).
- Exportar PDF y Excel — ambos devuelven 200 (confirma en vivo el fix del Senior Review previo,
  que corrigió `formato`→`format`).
- Defensa en profundidad confirmada por API directa: un vendedor recibe 403 en
  `GET .../vendors` y `GET .../export` aunque intente pegarle directo con su propio token.
- Sin iconografía de emoji (regla SCRUM-56) — set de iconos propio (`IcoBarChart`,
  `IcoDownload`, `IcoChevronDown/Left/Right`).

## Nota fuera de alcance de este batch

`.claude/worktrees/agent-ab86128af200e75f7` (atlanticerp-backend) sigue en disco con una copia de
`AdminContInternalCommissionControllerTest.php` — no se investigó su rama/estado (fuera del
alcance de este despacho de Pre-QA), pero corresponde limpiarlo si ya fue mergeado, según la
regla de higiene de worktrees del `CLAUDE.md` raíz.
