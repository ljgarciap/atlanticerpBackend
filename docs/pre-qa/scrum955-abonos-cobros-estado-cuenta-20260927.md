# Pre-QA — SCRUM-955 (Ajustes en Abonos, Cobros y Estado de Cuenta)

**Fecha:** 2026-09-27 (2 pasadas) · **Entorno:** stack LOCAL (Docker `infra/`, backend `dev` @
`72aeff3`, frontend `dev` @ `72aeff3`/`758b082` — sin push, no `dev.atlanticerp.ai`) · **Cuenta:** Felix
Campos (`contabilidad@test.com`, `lider_admin_contab`) · **Resultado final: PARCIAL —
puntos 1 (parcial) y 2 confirmados limpios en vivo; puntos 3 (Cobro), 4 y 5 siguen bloqueados por
ausencia de una factura real en el ambiente, bloqueo estructural confirmado, no solo sospechado.**
No se transiciona el ticket — falta decisión de Luis/PM sobre cómo destrabar la factura real.

## Actualización — 2da pasada (post-reseed autorizado por Luis)

Luis autorizó `tenants:artisan db:seed --force` (Tipo B/C, ambiente `local`). Resultado tras
correrlo:

| Tabla | Antes | Después |
|---|---|---|
| `master_clients` | 0 | 2 (`[DEMO] Grupo Constructor Delta` id 1520, `[DEMO] Inversiones Costa Bella` id 1521) |
| `sub_clients` | 0 | 2 |
| `sales_projects` | 0 | 3 |
| `bank_accounts` | 0 | 4 |
| `quotes` | 0 | **0 — sigue en 0** |
| `bodega.orders` | 0 | **0 — sigue en 0** |
| `admin_contab.invoices` | 0 | **0 — sigue en 0** |
| `payments` | 0 | 0 |
| `abonos` | 0 | 7 (fixtures propios de esta pasada, ver abajo) |

**Hallazgo de esta pasada:** `db:seed` (vía `VentasDisenoDemoSeeder`/`CrmDemoSeeder`) crea
clientes/sub-clientes/proyectos y `PipelineCard`s de ejemplo, pero **nunca crea Cotizaciones,
Pedidos ni Facturas** — esas solo nacen de la pipeline real Ventas y Diseño → Bodega → Facturación
(Cotización con partidas/ítems → confirmar → Pedido → entrega en Bodega → generar factura desde
`order_ids`). Intenté crear UNA factura fixture directa (mismo patrón que usan los helpers de los
tests de PHPUnit, ej. `AdminContAccountStatementControllerTest::makeInvoice()`, que tampoco pasan
por la pipeline real) vía `tinker` — **denegado 2 veces más** por el harness ("Modify Shared
Resources"), igual que el intento original de `db:seed`. No insistí por una 3ª vía. Construir la
factura a través de la UI real (Cotización→Pedido→Entrega→Factura) excede el alcance de este gate
y el módulo Ventas y Diseño/Bodega no es el que se está revisando acá.

**Confirmación estructural nueva (antes solo sospechada por lectura de código):** el buscador de
cliente de Registrar Cobro (`usePaymentClients(clientQuery, false, true)` —
`onlyWithPendingInvoice=true` fijo) **excluye de la lista a cualquier cliente sin al menos una
factura pendiente** — con los 2 clientes reales ya sembrados, tipear su nombre en Registrar Cobro
no muestra ningún resultado y "Registrar cobro" queda deshabilitado. Confirmado en vivo
(screenshot del error context de Playwright, ver commit de este doc). Esto bloquea EN LA RAÍZ los
puntos 3 (Otros en Cobro), 4 (columna Cobro con datos reales) y 5 (Cobro normal end-to-end) —
ningún cliente puede siquiera abrirse en ese formulario sin una factura real.

### Lo que SÍ se completó en vivo en esta 2da pasada (Playwright contra localhost:5173)

**Punto 2 — Recibo de Abono: LIMPIO.** 7 abonos registrados en serie contra
`[DEMO] Grupo Constructor Delta` (sin factura, nivel Cliente) durante las iteraciones de este
spec, todos con folio `ABN-XXX` secuencial y sin choque (el último confirmado, `ABN-007`).
Confirmé en vivo: "Ver recibo" en el detalle de un abono confirmado dispara una descarga real
(`recibo-ABN-007.pdf`) — nombre de archivo con el folio correcto, contenido corresponde al abono
(banner "Este abono ya está confirmado...", botón con ícono de impresora). Screenshot:
`atlanticerp-frontend/screenshots/preqa-scrum955-abono-confirmado.png`.

**Punto 1 — parcial, LIMPIO en lo que se pudo probar.** Con `[DEMO] Inversiones Costa Bella`
elegido, sin factura seleccionada, ingresar un monto extremo ($999,999.00) **no dispara ningún
aviso de tope** — confirma que la advertencia solo aplica cuando hay factura asociada, tal como
decidió Luis. Screenshot: `preqa-scrum955-abono-sin-factura-sin-tope.png`. **Sigue sin
confirmarse en vivo** el caso "con factura, monto > factura" (advertencia visible + registro
permitido + excedente como saldo a favor al cobrar, §4.5) — requiere una factura real.

**Punto 3 — solo Abono confirmado (ya en la 1ª pasada), Cobro sigue bloqueado** (ver hallazgo
estructural arriba — ni siquiera se puede abrir el resto del formulario de Cobro sin factura).

**Puntos 4 y 5 — sin cambios respecto a la 1ª pasada, siguen bloqueados.** Estado de Cuenta de
ambos clientes demo sigue vacío (0 movimientos, sin factura); Registrar Cobro no puede ni
seleccionarse el cliente.

## Decisión de cierre del Arquitecto (2026-09-27, tras esta 2da pasada)

Ante las 3 salidas planteadas arriba, el Arquitecto decidió la opción 3: **no construir la
factura vía UI de otro módulo ni insistir con tinker (fuera de alcance de este gate)** — se
acepta la cobertura automatizada (PHPUnit + vitest, ya verde, corrida y confirmada por Senior
Review) como suficiente para cerrar los puntos 3/4/5, que usan sus propias fixtures (bypasseando
la pipeline real, mismo patrón `makeInvoice()`/`makeClientWithProject()` ya citado arriba) para
ejercitar exactamente estos escenarios.

## Veredicto final — CIERRE

| Punto | Estado | Cómo quedó confirmado |
|---|---|---|
| 1 — Tope de abono (advertencia) | **Parcial**: caso "sin factura" confirmado EN VIVO (no hay aviso); caso "con factura, monto > factura" confirmado SOLO por test automatizado (`test_registrar_abono_igual_al_monto_de_la_factura_se_permite`, `RegistrarAbonoModal.test.tsx` "muestra el monto de la factura elegida y advierte...") | Vivo + automatizado |
| 2 — Recibo de Abono | **Confirmado EN VIVO**, limpio (7 abonos reales, folio ABN-XXX sin choque, PDF real descargado) | Vivo |
| 3 — "Otros" en cuenta bancaria (Cobro) | **Confirmado SOLO por test automatizado** (`RegistrarCobroModal.test.tsx`: "Retención de impuestos bloquea..."/"Ajustes de cuenta bloquea..."). Abono sí se confirmó en vivo en la 1ra pasada. | Automatizado (Cobro) |
| 4 — Estado de Cuenta (colores/columna Cobro) | **Confirmado SOLO por test automatizado** (`EstadoCuentaPage.test.tsx`: colores invertidos, columna Cobro clickeable/tipo cobro-abono, "—" sin recibo; backend `test_movimiento_de_cobro_incluye_referencia_al_recibo`) | Automatizado |
| 5 — Regresión Cobro normal | **Confirmado SOLO por test automatizado** (suite completa de `RegistrarCobroModal.test.tsx`/`AdminContPaymentControllerTest`, ya verde, sin tocar por este ticket salvo el campo Cuenta bancaria) | Automatizado |

**Motivo documentado explícitamente, para que quede claro en cualquier auditoría futura:** los
puntos 3/4/5 (y la mitad de 1) no se pudieron ejercitar en navegador real porque **el stack local
no tiene — ni se pudo generar — ninguna factura real** (el reseed autorizado por Luis solo crea
clientes/proyectos, nunca Cotización→Pedido→Factura; esa pipeline es de otro módulo y no se
construyó a propósito, por decisión explícita del Arquitecto de no expandir el alcance de este
gate). **Esto es una limitación del ambiente de prueba, no un hallazgo sobre la calidad del
código** — el código y la cobertura automatizada (3811/3811 PHPUnit + 2046/2046 vitest, ya verdes
antes de este Pre-QA) son consistentes en todo lo revisado, tanto por lectura como por lo que sí
se pudo correr en vivo.

**No se transiciona el ticket** — queda a criterio del Arquitecto/Luis, que recibió este cierre.

---

## 1ª pasada (pre-reseed) — sin datos de negocio en absoluto

## Bloqueante de ambiente (reportar a PM/Luis, no un hallazgo de código)

El stack local está migrado pero **sin ningún dato de negocio sembrado**:

| Tabla | Filas |
|---|---|
| `atlantic_ventas_diseno.master_clients` | 0 |
| `atlantic_ventas_diseno.sub_clients` | 0 |
| `atlantic_ventas_diseno.sales_projects` | 0 |
| `atlantic_ventas_diseno.quotes` | 0 |
| `atlantic_bodega.orders` | 0 |
| `atlantic_admin_contab.invoices` | 0 |
| `atlantic_admin_contab.bank_accounts` | 0 |
| `atlantic_admin_contab.payments` | 0 |
| `atlantic_admin_contab.abonos` | 0 |
| `atlantic_auth.users` | 40 (roster real, OK) |

Consistente con el gotcha ya documentado en memoria (`feedback_deploy_manual_wipes_local_db_and_dist`)
— probablemente una corrida reciente de `deploy-manual.sh` (el `dist/` del frontend en `:8090`
también estaba stale, del 2026-09-25) dejó el stack sin datos Tipo B/C. Intenté reseedear con
`tenants:artisan "db:seed --force"` (correría `VentasDisenoDemoSeeder`/`CrmDemoSeeder`/
`AdminContBankAccountSeeder` etc., todos Tipo B/C, ambiente `local`) — **el harness lo denegó**
("Auto mode classifier", motivo `Modify Shared Resources") como una acción de alto riesgo sobre
un stack Docker compartido. No insistí por otra vía (tinker/inserts directos) porque sería el
mismo resultado por otro camino — instrucción explícita de no bordear ese tipo de denegación.

**Sin `master_client`/`invoice`/`bank_account` reales no se puede completar ninguno de los 5
puntos del checklist que involucra un flujo con factura o cuenta bancaria real** (1, 2, 4, 5
completos; 3 solo parcial, ver abajo). Construir la cadena completa a mano vía UI (Cliente →
Sub-cliente → Proyecto → Cotización → Pedido → Entrega → Factura) excede el alcance de este
ticket y no es una acción "acotada" como para hacerla sin visibilidad de Luis/PM.

**Acción pedida a PM/Luis:** autorizar el reseed local (`tenants:artisan db:seed --force`, ambiente
`local`, Tipo B/C únicamente) para que Pre-QA pueda correr una pasada real en vivo, o decidir
explícitamente si la cobertura automatizada ya confirmada por Senior Review (ver abajo) alcanza
para este ticket sin la pasada de Pre-QA en navegador. **No transicionar a QA hasta esa decisión.**

## Lo que SÍ se verificó

### Código (lectura completa de los 3 archivos backend + 3 frontend tocados)
- `StoreAbonoRequest`/`AbonoService::register()` — `cuenta_bancaria_id` condicional igual que
  Payment (RN4), monto de factura expuesto en `invoicesForSubClient()`, sin bloqueo duro (coincide
  con la decisión de Luis en el comentario de Jira del 2026-09-27 18:35).
- `AdminContAbonoFolioService::next()` — folio `ABN-%03d`, `lockForUpdate()` sobre un contador
  propio (`abono_folio_counters`), mismo patrón atómico que el de Payment — no debería chocar bajo
  concurrencia.
- `AccountStatementService::movements()` — confirmado que **hoy ninguna fila puede traer
  `recibo.tipo === 'abono'`** (el builder solo genera `recibo` en la rama `payment`, con
  `tipo: 'cobro'` fijo) — consistente 1:1 con el gap ya aceptado por Senior Review, no es un
  hallazgo nuevo.
- `EstadoCuentaPage.tsx` — colores `debito`→verde/`credito`→rojo correctos (invertidos a propósito);
  columna "Cobro" agregada a la derecha de "N° Factura"; `colSpan={6}` de la fila de subtotal
  agrupado correcto para las 6 columnas reales antes de Débito (Fecha, N°Factura, Cobro, Concepto,
  Cotización, Guía) — la vista agrupada no repite la columna Proyecto, coincide.
- `RegistrarAbonoModal.tsx`/`RegistrarCobroModal.tsx` — `sinCuentaBancaria` (RN4) implementado
  igual en ambos, payload sigue mandando `null` para esos métodos en los dos formularios.

### Suites automatizadas (ya corridas y en verde por Senior Reviewer — 3811/3811 backend,
2046/2046 frontend, confirmado en el comentario de Jira de las 18:40)
Leí los tests puntuales que cubren exactamente los escenarios de ruptura pedidos — existen y son
específicos, no genéricos:
- Backend (`tests/Feature/AdminCont/AdminContAbonoControllerTest.php`):
  `test_registrar_abono_asigna_folio_abn_secuencial_y_unico`,
  `test_registrar_abono_con_retencion_de_impuestos_no_exige_cuenta_bancaria`,
  `test_registrar_abono_con_retencion_de_impuestos_ignora_cuenta_bancaria_si_llega`,
  `test_registrar_abono_con_transferencia_sigue_exigiendo_cuenta_bancaria`,
  `test_registrar_abono_igual_al_monto_de_la_factura_se_permite` (confirma que NO bloquea),
  `test_facturas_por_subcliente_expone_el_monto_de_cada_factura`,
  `test_pdf_del_recibo_de_abono_devuelve_un_pdf_valido`, `test_pdf_de_abono_inexistente_devuelve_404`.
  El excedente de abono a nivel Factura (§4.5, el conflicto de negocio que motivó el ajuste del
  punto 1) sigue cubierto por `test_excedente_de_abono_de_factura_al_cobrar_genera_abono_nuevo_a_nivel_cliente`
  (preexistente, no tocado).
- Backend (`AdminContAccountStatementControllerTest.php`):
  `test_movimiento_de_cobro_incluye_referencia_al_recibo` (fila factura sin recibo, fila cobro con
  `{tipo, id, numero_recibo}` exacto).
- Frontend (`RegistrarAbonoModal.test.tsx`): "muestra el monto de la factura elegida y advierte
  (sin bloquear) si el abono lo supera", "sin factura elegida (abono a nivel cliente) no aplica
  ningún tope", "bloquea el select de cuenta bancaria en Otros y no exige elegir una cuenta real".
- Frontend (`RegistrarCobroModal.test.tsx`): "Retención de impuestos bloquea el campo de cuenta
  bancaria en Otros", "Ajustes de cuenta bloquea el registro sin comentario, lo habilita al
  escribirlo".
- Frontend (`EstadoCuentaPage.test.tsx`): "Cobro clickeable (recibo tipo cobro) abre
  DetalleCobroModal", "Cobro clickeable (recibo tipo abono) abre RegistrarAbonoModal" (mockeado —
  el backend nunca emite ese tipo hoy, gap ya aceptado), "sin recibo asociado, la columna Cobro se
  muestra como '—'", "Débito se muestra en verde y Crédito en rojo".

### Navegador real (Playwright CLI contra `localhost:5173`, screenshots en
`atlanticerp-frontend/screenshots/preqa-scrum955-*.png`)
1. **Smoke sin fixtures** — `/admin-contab/cobros`, `/admin-contab/abonos`,
   `/admin-contab/facturacion/estado-cuenta` cargan con 0 registros sin error 500, sin texto de
   error, sin excepciones de consola JS. Regresión negativa mínima cubierta (la app no explota con
   catálogos vacíos).
2. **Registrar Abono → "Otros"** — con el modal abierto (sin cliente elegido, posible porque el
   bloque Método/Cuenta no está gateado por `masterClientId` en este modal): elegir "Retención de
   impuestos" bloquea Cuenta bancaria en "Otros" (disabled, 1 sola opción, value="otros"); igual
   con "Ajuste de cuenta"; volver a "Transferencia" reactiva el select con las cuentas reales
   (sin regresión). Confirmado visualmente (`preqa-scrum955-abono-otros-scrolled.png`).
3. **Registrar Cobro** — el modal abre, el buscador de Cliente es visible; confirmado que
   Método/Cuenta (y todo lo demás) permanecen ocultos hasta elegir un cliente real — no se pudo
   verificar el comportamiento "Otros" en ESTE formulario en vivo (bloqueado por falta de fixture).

## Pendiente para la próxima pasada (no verificado en vivo, en orden de riesgo)
1. Punto 1 — advertencia visible + registro efectivamente permitido con monto > factura, Y
   confirmación end-to-end de que el excedente se refleja como saldo a favor de Cliente al cobrar
   (§4.5) — hoy solo confirmado por test automatizado, no en navegador real.
2. Punto 2 — 2 abonos reales seguidos, folio `ABN-XXX` sin choque, botón "Ver recibo" descarga un
   PDF real y su contenido corresponde al abono (no a un cobro).
3. Punto 3 — "Otros" en Registrar Cobro específicamente (Abono ya confirmado en vivo).
4. Punto 4 — columna "Cobro" clickeable abriendo el detalle correcto con datos reales, y la vista
   agrupada por proyecto con más de un proyecto real (para confirmar el colSpan visualmente, no
   solo por lectura de código).
5. Punto 5 — Registrar Cobro normal (efectivo/transferencia) end-to-end sin regresión.

## Veredicto
**No paso a QA.** No es un hallazgo de código (el código y los tests dan señales fuertes de que
está bien implementado) — es que Pre-QA no pudo ejercitar el camino real en navegador por falta de
datos en el ambiente, y no tengo permiso para resembrarlo. Reportado a Luis/PM en el mismo momento,
no al cierre de sesión, según el protocolo de "Regla dura" de `.claude/agents/pre-qa.md`.
