# Senior Review — Bloque B4 "Reubicación entre bodegas" (SCRUM-454/457/458/459)

**Fecha:** 2026-07-24
**Revisor:** Senior Reviewer (Claude, lente Senior Reviewer)
**Alcance:** `atlanticerp-backend` `1f72ce4..HEAD` (b07b9a9, c7b6795) + `atlanticerp-frontend` `1f2bf6f..HEAD`
(7b02819, f61f5d0, c850007)
**Diseño de referencia:** `docs/adr/ADR-SCRUM454-459-reubicacion-bodegas.md` (Status: Approved)

## Verificaciones ejecutadas

| Check | Resultado |
|---|---|
| PHPStan Level 8 (`vendor/bin/phpstan analyse`) | `[OK] No errors` |
| `infra/test.sh --filter=Bodega` | `OK (231 tests, 988 assertions)` |
| `npx tsc --noEmit` | Sin salida — compila limpio |
| `npm run test -- --run` (suite completa) | `61 archivos / 545 tests passed` (incl. `BodegasPage.test.tsx`, 13/13) |

Ningún test rojo, ningún error de tipos. Se corrió `infra/test.sh` en solitario (sin otro proceso
concurrente tocando el Postgres compartido de dev), como pide la nota de la tarea.

---

## Hallazgos

### 🟢 Approved

- **Concurrencia — el fix crítico del incidente de `AdjustmentRequestController` SÍ se replicó
  correctamente.** En `RelocationRequestController::approve()` (líneas 237–275) y `reject()`
  (líneas 306–324), el `RelocationRequest::lockForUpdate()->find($id)` y el chequeo
  `!$relocationRequest->isPending()` viven **dentro** del `DB::transaction()`, no antes. Verificado
  también con test real de concurrencia secuencial
  (`test_aprobar_dos_veces_seguidas_solo_aplica_la_reubicacion_una_vez`): el segundo `approve()`
  devuelve 422 y el stock queda en 6/4, no 2/8 ni negativo. `KardexService::registrar()` además
  hace su propio `lockForUpdate()->firstOrCreate()` sobre `ProductWarehouseStock` (transacción
  anidada vía savepoint), doble cinturón de seguridad a nivel de fila de stock.
- **RN1/RN2 validadas server-side, no solo confiando en el frontend.** `destination_warehouse_id`
  usa la regla `different:origin_warehouse_id` de Laravel (no una CHECK constraint cruzada en la
  migración — decisión documentada y razonable, mejor UX con 422+mensaje que un error de
  constraint). `cantidad`/`motivo`/`destination_warehouse_id` son `required`. Cubierto por 5 tests
  de validación independientes (`test_store_con_bodega_destino_igual_a_origen_retorna_422`,
  `test_store_sin_cantidad_retorna_422`, `test_store_sin_motivo_retorna_422`,
  `test_store_sin_bodega_destino_retorna_422`).
- **Gate "exclusivo de Aprobador" robusto y no bypasseable por rol.** `resolveApproverAccess()` reutiliza
  exactamente `ComprasSettings::current()->mark_approver_user_id`, mismo mecanismo palabra por
  palabra que `AdjustmentRequestController::resolveApproverAccess()` — una sola fuente de verdad de
  quién es Aprobador. Verificado con `test_aprobar_no_siendo_mark_retorna_403`: un segundo usuario con
  `bodega.approve` pero que no es Aprobador recibe 403 y el estado permanece `pendiente`. Los permisos de
  ruta (`bodega.read`/`write`/`approve` en `routes/bodega.php` líneas 61–65) son defensa en
  profundidad correcta sobre el gate de negocio, no lo sustituyen.
- **`AuthenticateJwt` aplicado globalmente** vía el grupo `api` en `bootstrap/app.php` (línea 45) —
  ninguna ruta de `bodega.php` quedó accidentalmente pública; `permission:*` corre después de
  autenticación real.
- **Contrato consistente con el resto de `WarehouseController::show()`.** `buildEspacioLibreResponse()`
  devuelve exactamente el mismo shape de nivel superior (`warehouse`/`kpis`/`ubicaciones`/`data`)
  que la rama normal — no se repitió el bug CRÍTICO de Pre-QA 2026-07-21 (falta de `kpis` rompiendo
  `data.kpis.responsable` en el frontend). Verificado con
  `test_chip_espacio_libre_expone_ubicaciones_vacias` y el `assertJsonStructure` explícito.
- **Fix de contrato (`c7b6795`) bien resuelto y honestamente documentado.** El gap real
  (`catalog_product_id` ausente en `formatRow()`) fue encontrado por Frontend Dev construyendo el
  modal, no encubierto con un workaround permanente — el commit del frontend (`c850007`) elimina
  limpiamente el stopgap de red extra (`useSearchAdjustmentProducts` + búsqueda por `reference`)
  una vez que el backend expuso el campo real. Los tests de ambos repos se actualizaron en
  consecuencia (`WarehouseControllerTest::test_show_solo_incluye_stock_positivo_de_esa_bodega`
  ahora afirma `catalog_product_id`; `BodegasPage.test.tsx` ya no mockea el endpoint de búsqueda).
- **`is_active` en vez de hard-delete para `WarehouseLocation`**, tal como pide la ADR — cubierto
  por `test_update_permite_desactivar_sin_borrar` (la fila sigue existiendo, solo cambia el flag) y
  `test_chip_espacio_libre_ignora_ubicaciones_inactivas`.
- **`UNIQUE(warehouse_id, codigo)` con el alcance correcto** — mismo código puede repetirse en otra
  bodega, cubierto explícitamente por `test_store_mismo_codigo_en_otra_bodega_es_valido`.
- **Frontend — sin regresiones de reglas duras del cliente.** Grep sobre el diff completo: cero
  emojis, cero `window.alert/confirm/prompt`. Modal de rechazo, badges de estado y botón de cierre
  (`IcoClose`) siguen el mismo patrón visual que `SolicitudAjustePage.tsx` (confirmado línea por
  línea contra ese archivo). El vocabulario de estados (`pendiente|aprobada|rechazada` en minúscula)
  se documentó explícitamente en `types/bodega.ts` como distinto del de `AdjustmentEstado`
  (mayúscula) — buena prevención de un bug de i18n/lookup silencioso.
- **Discriminador de tipo en runtime bien pensado** (`isWarehouseEmptyLocationRow`, `types/bodega.ts`
  líneas 1341–1345): distingue `WarehouseProductRow` de `WarehouseEmptyLocationRow` por la presencia
  de `stock_id`, más robusto que confiar solo en el estado del chip (que puede quedar stale durante
  un refetch in-flight) — el propio comentario en `BodegasPage.tsx` explica el porqué.
- **Bandeja de solicitudes reinicia el filtro a "Todas" al reabrir** (Escenario 1, REQ-389) resuelto
  sin lógica ad-hoc: el componente se desmonta al cerrar (`{showRequestsModal && <...>}`), así que
  el `useState('todas')` arranca de cero. Verificado con test dedicado.

### 🟡 Suggestions (no bloqueantes)

1. **`resolveApproverAccess()` duplicado palabra por palabra en `AdjustmentRequestController` y
   `RelocationRequestController`.** La ADR ya lo señala como decisión aceptada de Backend Dev
   ("puede extraer a un trait compartido si lo prefiere, no es bloqueante"). Con dos controllers
   ya en el mismo estado, y aparentemente un tercer módulo (`purchase-order`, gate de Aprobador también
   mencionado en `routes/compras.php`) usando el mismo patrón, sugiero extraerlo a un trait
   (`App\Modules\Compras\Support\MarkApprovalGate` o similar) la próxima vez que se toque
   cualquiera de los dos controllers — no amerita un ticket propio todavía.
2. **Sin validación de stock disponible en origen antes de aprobar una reubicación.**
   `RelocationRequestController::approve()` llama `KardexService::registrar()` con
   `cantidad: -$relocationRequest->cantidad` sin verificar que
   `ProductWarehouseStock` en la bodega origen tenga al menos esa cantidad. `KardexService::registrar()`
   tampoco tiene un piso — el `saldoResultante` puede quedar negativo sin error. A diferencia de
   "Ajuste" (que corrige discrepancias y puede tener legítimamente sentido ir a valores
   inesperados), una Reubicación mueve stock físico real: aprobar una reubicación de 50 unidades
   desde una bodega que solo tiene 10 dejaría el origen en -40, un dato de inventario físicamente
   imposible. Esto **no es una regresión de este ticket** — el mismo patrón (sin piso) ya existe en
   el "Restar" de `AdjustmentRequestController::approve()`, y la ADR no lista esto como RN. No lo
   bloqueo porque está fuera del alcance aprobado y replica un patrón ya aceptado en el módulo
   hermano, pero recomiendo que quede anotado como ítem de backlog compartido entre Ajustes y
   Reubicación (ej. `KardexService::registrar()` podría exponer un modo opcional
   "no permitir negativo" y que ambos controllers lo usen al restar/mover stock) — decisión de
   Arquitecto/Luis, no una corrección que un dev deba hacer solo para cerrar este bloque.
3. **`WarehouseLocationController::store()`/`update()` sin límite de longitud en `codigo`.** Menor,
   cosmético — un código de ubicación absurdamente largo no rompe nada hoy (columna `string` por
   defecto en Postgres/Laravel es `varchar(255)`), pero un `max:50` o similar evitaría datos de
   catálogo poco realistas.
4. **Test `BodegasPage.test.tsx` — cierre de modal vía `b.textContent === ''`** (línea ~691, test
   "reinicia el filtro a Todas"): localizar el botón de cerrar por "es el único botón sin texto"
   es frágil — si se agrega cualquier otro ícono sin texto al panel en el futuro, el test podría
   matchear el botón equivocado sin fallar de forma obvia. Sugiero un `aria-label="Cerrar"` en el
   botón de `IcoClose` (mejora también accesibilidad) y `getByLabelText` en el test — no bloqueante
   para este batch, pero vale la pena la próxima vez que se toque ese archivo.

### 🔴 Blockers

Ninguno.

---

## Resumen para el Arquitecto/PM

**Bloque B4 aprobado — puede pasar a Visual Reviewer / Pre-QA.**

- Backend: PHPStan limpio, 231/231 tests de Bodega en verde, el fix de concurrencia del incidente
  de `AdjustmentRequestController` se replicó correctamente y está cubierto por test real
  (doble-approve concurrente), RN1/RN2 validadas server-side, gate de Aprobador robusto y consistente
  con el patrón ya aprobado.
- Frontend: `tsc --noEmit` limpio, 545/545 tests (61 archivos) en verde, sin emojis ni
  `window.alert/confirm/prompt`, patrón visual consistente con `SolicitudAjustePage.tsx`.
- El fix de contrato del segundo commit backend (`c7b6795`, exponer `catalog_product_id`) está bien
  resuelto y permitió que el frontend simplificara un workaround fràgil (commit `c850007`) — buen
  ejemplo de "encontrado durante el propio desarrollo, corregido antes de pedir review", no una
  deuda oculta.
- 3 suggestions no bloqueantes documentadas arriba (duplicación de `resolveApproverAccess`, falta de piso
  de stock en Reubicación — comparte el gap con Ajustes, y un test frágil) — ninguna requiere volver
  a Backend/Frontend Dev antes de Pre-QA. La de "piso de stock" vale la pena que el Arquitecto la
  registre como ítem de backlog compartido Ajustes/Reubicación para una decisión de producto con
  Luis (¿debe existir ese piso, o hay casos legítimos donde no?), no como bug de este ticket.
