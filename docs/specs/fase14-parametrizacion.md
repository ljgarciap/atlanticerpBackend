# Fase 14 — Reglas y catálogos configurables por empresa (2026-10-31)

**Estado**: spec aprobada por Luis (2026-10-31, orden 14a, 14d, 14c, 14b). **14a mezclada** (§8); **14d mezclada** (§9); **14c mezclada** (§10); **14b mezclada** (§11). **Fase 14 completa.** Origen: Grupo 3 de `docs/flujos/candidatos-priorizados.md`.

## 1. Objetivo
Que los criterios de negocio que hoy viven en el código (cuándo una orden de compra necesita aprobación, qué formas de pago existen, qué motivos y roles se ofrecen) sean DATOS por empresa, editables por el superadmin sin desplegar. El ítem 3.1 es el único que incumple una regla dura del workspace (no hardcodear umbrales ni criterios de negocio).

## 2. Hallazgos (medidos sobre `master`)
| # | Pieza | Hoy | Acoplamiento |
|---|---|---|---|
| 3.1 | Aprobación de una orden de compra | `PurchaseOrder::computeRequiresPrimaryApproval(shipping_type, who_pays_shipping, …)`: requiere aprobación solo si envío **aéreo pagado por la empresa**; el monto no interviene. Se evalúa al crear y al editar (`PurchaseOrderController` líneas ~379 y ~466) y guarda `requires_primary_approval` en la orden. Lo leen la bandeja de pendientes, Gerencia y el bloqueo `isBlockedByPrimaryApproval()` | Medio: un solo punto de cálculo, tres lectores del campo guardado |
| 3.2 | Formas de pago | Dos listas: cobros (`AdminContPayment::METODOS`, 9 valores, con `METODOS_SIN_CUENTA_BANCARIA`, etiquetas del PDF y el conjunto «inmediato» del arqueo) y caja chica (`AdminContPettyCashReport::FORMAS_PAGO`, 4). Yappy es un valor regional (Panamá). Los reportes de ventas por método traen una columna fija por método (`yappy`, …) | **Alto**: el método gobierna cuenta bancaria, arqueo, PDF y reportes |
| 3.3 | Listas fijas | Roles de contacto del CRM (`Contact::ALLOWED_TYPES`: developer/arquitecto/encargado/contacto, y `client/architect/other` en la validación del flujo de venta), motivos de ajuste (6) y de devolución de Bodega, especialidad del técnico interno (`general`/`warranty`; la del externo es texto libre) | Bajo-medio; `warranty` tiene significado de sistema |
| 3.4 | Umbral `primary_approval_threshold` | El catálogo de flujos dice que lo comparten notas de crédito y compras. **En el código solo lo lee `CreditNoteService`** (compras no lo usa): la premisa del candidato no se confirma | Bajo: es un problema de nombre y de etiqueta |
| 3.5 | Tramos de comisión | Se muestran con límites (0–20.000 / 20.001–35.000) distintos de los aplicados (el 2 % arranca en 20.000) | Bajo: solo presentación |

## 3. Diseño propuesto
**14a — Reglas de aprobación de compras (3.1).** Tabla `purchase_approval_rules` (`{tenant}_compras`): `name`, `sort_order`, `is_active`, condiciones opcionales (`shipping_type`, `who_pays_shipping`, `min_amount`, `max_amount`; vacío = cualquiera) y `requires_approval` (bool). Se evalúa la **primera regla activa que coincide, por orden**; si ninguna coincide, no requiere aprobación (igual que hoy para todo lo que no sea aéreo pagado por la empresa). Migración que siembra UNA regla equivalente a la actual (aéreo + paga la empresa → requiere), de modo que el comportamiento por defecto no cambia. `computeRequiresPrimaryApproval` pasa a consultar las reglas (con memo por petición, patrón `CatalogCache`). CRUD bajo `superadmin.all` (sin permiso nuevo → sin riesgo de fuga por nivel compartido) y pestaña «Reglas de aprobación» en Ajustes de Compras. Quién aprueba NO cambia (aprobador primario o suplente, Fase 12b). No se reutiliza `reglas_aprobacion` de Gerencia: modela «tipo de solicitud → aprobadores», no condiciones sobre la orden.

**14b — Formas de pago (3.2).** Dominio de catálogo `payment_method` (mecanismo de la Fase 7) con propiedades `sin_cuenta_bancaria`, `inmediato` (arqueo) y `caja_chica` (aparece en caja chica). Las dos de sistema (`retencion_impuestos`, `ajuste_cuenta`) no se borran ni cambian propiedades. El sembrado depende del país del tenant (Yappy solo en Panamá, vía `config/regions.php`); un método en uso no se borra. Etiquetas del PDF y del frontend salen del catálogo. Los reportes de ventas por método NO cambian (decisión 4): conservan sus columnas actuales.

**14c — Listas fijas (3.3).** Cuatro dominios nuevos: `contact_role`, `adjustment_reason`, `return_reason`, `technician_specialty`. `warranty` queda como clave de sistema (la lógica de garantías pregunta por ella); `OTRO_ESPECIFICAR` conserva su comportamiento «pide texto».

**14d — Umbral y tramos (3.4 y 3.5).** 3.4: confirmar con un test que compras no lee el umbral y renombrar su etiqueta/clave a «umbral de aprobación de notas de crédito» (migración de renombre de columna, `down` reversible). 3.5: mostrar los tramos tal como se aplican (límite inferior inclusivo), sin tocar el cálculo.

## 4. Criterios de aceptación (comunes)
Panamá sin cambio visible con los datos por defecto; migraciones aditivas y reversibles; tests de equivalencia (la regla sembrada produce el mismo resultado que el código actual para toda combinación de tipo de envío y pagador); una regresión por ítem; suite completa del backend y del frontend verdes antes de abrir cada PR; verificación en navegador con Playwright CLI (crear una regla propia y ver que una orden la respeta).

## 5. Riesgos
- 14a toca dinero y aprobaciones: una orden ya creada conserva su `requires_approval` guardado (no se recalcula en bloque); solo se reevalúa al editarla, como hoy.
- Una regla mal configurada puede dejar órdenes sin aprobación: la pantalla muestra una vista previa («con estas reglas, una orden aérea de 3.000 pagada por la empresa SÍ/NO requiere aprobación») y toda edición queda en auditoría.
- 14b: cambiar el conjunto «inmediato» altera qué cobros entran al arqueo del día; las entradas de sistema quedan bloqueadas y la migración no cambia ningún valor actual.

## 6. Decisiones de Luis (2026-10-31)
1. **Monto de la regla (14a)**: sobre el **total a pagar** al proveedor (productos + envío aéreo pagado por la empresa + costos adicionales). ✅
2. **Evaluación (14a)**: **primera regla activa que coincide, por orden**; sin coincidencia = no requiere aprobación. ✅
3. **3.4**: **renombrar la clave** del umbral a «umbral de notas de crédito» (migración reversible). ✅
4. **Alcance de 3.2 (14b)**: **solo cobros y caja chica**; los reportes de ventas por método conservan sus columnas actuales. ✅
5. **Entrega**: 4 sub-fases (14a, 14d, 14c, 14b, en ese orden), cada una con su PR por repo y su «adelante» — propuesta recomendada, no se preguntó; se ajusta si Luis indica otro orden. ⏳

## 7. No-alcance
Quién aprueba (Fase 12b), umbral de notas de crédito como regla por monto/tipo, formas de pago por cliente, y cualquier cambio de contabilidad.

## 8. Resultado de 14a (reglas de aprobación de compras)
- **Tabla** `{tenant}_compras.purchase_approval_rules` (migración `2026_11_02_100000`, reversible) con UNA regla sembrada equivalente a la del código: envío aéreo pagado por la empresa → requiere aprobación. Test de equivalencia sobre las 12 combinaciones de tipo de envío y pagador.
- **Evaluación**: `PurchaseApprovalRules` (memo `scoped` por petición, patrón `CatalogCache`): primera regla activa por `sort_order` y `id` cuyas condiciones coinciden; límites de monto inclusivos; sin coincidencia no requiere. `PurchaseOrder::computeRequiresPrimaryApproval(envío, pagador, total)` delega en ella.
- **Total a pagar** (decisión 1): la regla se evalúa DESPUÉS de guardar las líneas (`applyApprovalRule`), con `payableTotal()` (productos + envío aéreo de la empresa + costos adicionales). Al crear fija también el estado inicial («Por aprobar» o «Pendiente/Por liquidar»); al editar reevalúa el indicador y no toca el estado, como antes. Una orden ya creada no se recalcula en bloque.
- **API** (solo superadmin, `/api/admin/purchase-approval-rules`: listar, crear, editar, borrar y `preview`); validación (máximo ≥ mínimo, el pagador solo aplica al envío aéreo); toda escritura queda en auditoría (`LogsAuditTrail`).
- **Frontend**: panel «Reglas de aprobación de órdenes» en Ajustes de Compras (solo superadmin) con lista, alta/edición, borrado con confirmación y vista previa.
- **Verificación**: backend 5227 tests / 0 fallos y PHPStan 0; frontend 247 archivos / 3015 tests y `tsc` limpio; Playwright (crear una regla por monto, la vista previa nombra la regla que decide, borrar).
- **Abierto**: el flujo de Insumos de Servicios crea órdenes sin pasar por esta evaluación (nacen sin aprobación, como antes).

## 9. Resultado de 14d (umbral y tramos)
- **3.4** — migración `2026_11_03_100000` (reversible) renombra `fiscal_settings.primary_approval_threshold` → `credit_note_approval_threshold` (el valor se conserva; el servicio pasa a `creditNoteApprovalThreshold()`; la clave del resumen de notas de crédito y del frontend cambia igual). `CreditNoteThresholdIsolationTest` demuestra que cambiar el umbral no altera qué órdenes de compra requieren aprobación (Compras decide con `purchase_approval_rules`).
- **3.5** — al revisar el código apareció algo más que un problema de rótulos: la tabla de comisiones **internas** de Admin & Contab (sembrada 0–20.000 y 20.001–35.000…) dejaba un hueco (un total de 20.000,50 no caía en ningún tramo y daba 0 %); la de Ventas (19.999,99 / 20.000) no lo tiene. **Decisión de Luis (2026-11-01): cerrar el hueco y mostrar «desde».** `CommissionOrderService::percentFor` elige el tramo de mayor `monto_minimo` que no supera el total (límite inferior inclusivo; `monto_maximo` queda informativo); la tabla del modal rotula «Menos de X / Desde X hasta menos de Y / Desde X». El cálculo de Ventas no cambia. Test `CommissionTierLookupTest` (8 totales, incluidos los bordes y el hueco).
- **Verificación**: ver los PR (suite completa de ambos repos, PHPStan, `tsc`).
- **Abierto**: dos tablas de tramos con límites distintos (Admin & Contab 20.001, Ventas 20.000) para el mismo concepto; no se alinean sin decisión de negocio.

## 10. Resultado de 14c (listas fijas)
- **Cuatro dominios** de catálogo (`config/catalogs.php`, migración `2026_11_04_100000`, reversible), sembrados con los valores y el orden de siempre: `contact_role` (4), `adjustment_reason` (6), `return_reason` (6) y `technician_specialty` (2). Cero cambio de comportamiento con los datos por defecto.
- **Entradas de sistema** (no se borran ni se desactivan): las 4 de contacto (son columnas de «proyecto» que importa el directorio), `OTRO_ESPECIFICAR` y `otra` (piden texto libre), `general` y `warranty` (la lógica de garantías pregunta por `warranty`).
- **Validación**: `ContactController`, `AdjustmentRequestController`, `CustomerReturnController` y `StoreInternalTechnicianRequest` validan contra `CatalogService::keys(...)` en lugar de la constante; las constantes de los modelos se conservan (las usan los tests y el import del directorio).
- **Frontend**: `useFixedLists` (`useAdjustmentReasons`, `useReturnReasons`, `useTechnicianSpecialties`) con respaldo a los valores y traducciones de siempre; migrados la solicitud de ajuste, la nueva devolución, el detalle de devolución y las tres vistas de técnico interno. Los tipos `AdjustmentMotivo`, `CustomerReturnReason` e `InternalTechnicianSpecialty` pasan a `string`.
- **Verificación**: backend 5240 tests / 0 fallos y PHPStan 0; frontend 248 archivos / 3019 tests y `tsc` limpio; `FixedListCatalogsTest` (valores sembrados = constantes, claves de sistema, una entrada propia se acepta y una inactiva se rechaza, un técnico con especialidad propia se crea); Playwright: una especialidad propia aparece en el alta de técnico.
- **Alcance decidido**: `contact_role` solo cubre los contactos del CRM (`Contact.tipo`); el directorio y su importación siguen con las 4 columnas de proyecto, y los roles de contacto del flujo de venta (`client`/`architect`/`other`) NO cambian porque `architect` tiene lógica (`PipelineCard::contacts`). La API de contactos no tiene pantalla que elija el rol.
- **Abierto**: una etiqueta por entrada (idioma de la empresa): en inglés, las listas muestran el texto sembrado en español hasta renombrarlas; el motivo de ajuste propio no se vio en navegador (el formulario pide añadir una línea de producto), solo en pruebas unitarias y de API.

## 11. Resultado de 14b (formas de pago)
- **Dominio `payment_method`** (`config/catalogs.php`, migración `2026_11_05_100000`, reversible) con tres propiedades booleanas: `sin_cuenta_bancaria` (no pide cuenta), `inmediato` (se confirma al registrarse y entra al arqueo del día) y `caja_chica` (se ofrece al pagar un reporte de caja chica). Sembrado = valores de siempre: sin cuenta, retención de impuestos y ajustes de cuenta; inmediatas, efectivo y esas dos; caja chica, transferencia, cheque, efectivo y Yappy.
- **De sistema** (no se borran ni cambian de propiedades, solo se renombran): `retencion_impuestos` y `ajuste_cuenta`.
- **Yappy por país**: `config/regions.php` `local_payment_methods` (PA → Yappy). Se siembra si el país lo ofrece o si la empresa ya tiene cobros, abonos o reportes de caja chica con él; nunca queda un registro sin su forma de pago.
- **Uso**: `usage` del catálogo admite ahora una lista de pares modelo/columna; una forma en uso en cobros, abonos o caja chica no se desactiva.
- **Backend**: fachada `AdminCont\Services\PaymentMethods` (`keys`, `cajaChicaKeys`, `sinCuentaBancaria`, `inmediato`, `inmediatos`, `label`, `labels`); las 4 reglas de validación de cobro/abono, `PaymentService`, `AbonoService`, el arqueo (`DailyCashCountService`), el reporte de caja chica y las etiquetas del recibo, el arqueo en PDF y el documento fiscal salen del catálogo. Retiradas `METODOS_SIN_CUENTA_BANCARIA`, `METODOS_CONFIRMACION_INMEDIATA` y `AdminContPettyCashReport::FORMAS_PAGO`.
- **Reportes de ventas por método**: sin cambios (decisión 4). Conservan `AdminContPayment::METODOS` y `METODO_LABELS` como sus 9 columnas. El mapeo a MSL (`MslPaymentMethodMapper`) tampoco cambia: una forma propia viaja como «otro» (99) con su etiqueta.
- **Frontend**: hook `usePaymentMethods` (respaldo a las nueve de siempre) en el cobro (v1 y v2), el abono, los filtros de historial y abonos, y el pago y detalle de caja chica. `PaymentMethod` y `PettyCashFormaPago` pasan a `string`.
- **Abierto**: una etiqueta por entrada (idioma de la empresa); `tarjeta` conserva la búsqueda de cuenta por tipo de cuenta (`defaultBankAccountFor`), pero no es de sistema: si se desactiva, el cobro con tarjeta deja de ofrecerse sin romper nada más.
