# Pre-QA LOCAL — Batch A correcciones UAT Admin&Cont (SCRUM-935, SCRUM-928, SCRUM-934) + regresión B1a/B1b

Fecha: 2026-09-18 · Entorno: stack Docker local (nginx :8090), ramas `dev` de backend y frontend, trabajo
de los 3 tickets ya commiteado localmente por el Arquitecto durante la pasada (5 commits: 66d079f B1a,
0058aae 935, 0edcfe1 928, 4fb0ecf 934, 03994bb docs) + B1b (`family_root_id`/`tipo`/`invoice_lines`) todavía
sin commitear. El contenido probado es el mismo. Cuentas: roster real local (`conta@` = Contabilidad,
`lider_admin_contab`; `mbekhar@` = Aprobador; password = email). Cuenta demo `designer@atlantic.test` solo para
el chequeo de rol sin permiso.

Verificación de que se servía el código actual: el bundle servido por nginx estaba viejo (index-CCstiipQ.js,
sin las cadenas nuevas); se corrió `npm run build` (solo escribe `dist/`) y el bundle nuevo
(index-BKTfUB2p.js) contiene "Total facturado en el día" y "Total pasado a pedido en el día". Backend: la llamada
real a `GET /admin-contab/cash-position/daily-count` ya devolvía `total_facturado_dia` /
`total_pasado_a_pedido_dia`, y los 4 Excel salieron con el formato nuevo (no hizo falta reiniciar laravel/horizon).

## Veredicto por ticket

| Ticket | Veredicto |
|---|---|
| SCRUM-935 | PASADA LIMPIA |
| SCRUM-934 | PASADA LIMPIA (con 2 observaciones BAJO que requieren decisión, no bloquean) |
| SCRUM-928 | BLOQUEANTE (1 MEDIO, fix de una línea: PQ-928-1). El resto del ticket pasa limpio |
| Regresión B1a/B1b | PASADA LIMPIA (Facturación/Cobros/Abonos/NC/Estado de Cuenta sin cambio de comportamiento) |

## Hallazgos

| ID | Sev | Ticket | Descripción | Estado |
|---|---|---|---|---|
| PQ-928-1 | MEDIO | 928 | El campo DV (`CreateClientModal` y mini-form de `ClientPicker`) tiene `maxLength=2` y además filtra `\D` en `onChange`. Un PEGADO con espacio/guion/texto inicial (`" 81"`, `"-81"`, `"DV 81"`) lo trunca el navegador a 2 caracteres ANTES del filtro (`" 8"` -> `"8"`) y el DV queda mal en silencio (botón habilitado, se guarda "8"). Un DV erróneo se rechaza recién en la facturación electrónica. Reproducido con Playwright (`keyboard.insertText(' 81')`). Fix sugerido: quitar `maxLength` (dejar solo `.replace(/\D/g,'').slice(0,2)`). | REPRODUCIDO |
| PQ-928-2 | BAJO (decisión) | 928 | El Recibo de pago PDF ("Cuenta destino: Banco General — corriente ****4554") sigue enmascarado: no consume `paymentBlock()` sino `AdminContBankAccount::label()`. El ticket limita el alcance a Factura ("no modifica ningún otro documento") pero D-6 dice "nunca `****XXXX` en documentos al cliente". No lo autoclasifico: decide Arquitecto/PM. | REPRODUCIDO |
| PQ-928-3 | BAJO (decisión) | 928 | Con cuenta activa SIN `numero_cuenta_completo`, en el PDF de Factura el nombre del banco desaparece (la línea es "Cuenta número: —" porque el banco solo iba dentro de `cuenta_pago`). En Estado de Cuenta (PDF/Excel/pantalla) sí se conserva la fila "Banco". Es lo que pide D-6, pero el criterio "banco, responsable, etc. permanece sin cambios" no se cumple en ese caso. | REPRODUCIDO |
| PQ-928-4 | BAJO | 928 | El 422 dice "El campo tax id dv es obligatorio." (falta etiqueta de atributo en `lang/es`). Solo lo ve quien llama a la API directo o si el frontend muestra el mensaje crudo. | REPRODUCIDO |
| PQ-934-1 | BAJO (decisión) | 934 | `total_facturado_dia` excluye facturas con `anulada_at` (solo lo setea "Corrección de datos"). Una factura anulada con NC "Anulación completa / cancelado" (aprobada por Aprobador) NO tiene `anulada_at` y SIGUE contando. Coincide con la letra del código y del Libro de facturas (factura + NC negativa), pero un lector del indicador puede esperar que una factura cancelada no cuente. | REPRODUCIDO (F-0006 + NC-0002) |
| PQ-934-2 | BAJO | 934 | Arqueos ya cerrados ANTES de este cambio (sin los indicadores en el `snapshot`) se reconstruyen en cada lectura "a la hora del cierre" con las tablas vivas; anular después una factura de ese día cambia el valor (reproducido 20 -> 0). Los arqueos cerrados de ahora en adelante SÍ quedan congelados (verificado). Solo afecta históricos previos; el backfill de snapshot los congelaría. | REPRODUCIDO |
| INFO-1 | info | 935 | La columna "Núm." pasó de texto a número (mismo valor visible). Los montos son numéricos con `$#,##0.00`. | — |
| INFO-2 | info | Paso 0 | El número de cuenta completo NO está sembrado: sin `ADMIN_CONTAB_BANK_ACCOUNT_NUMBER` el seeder no hace nada, así que en dev/test/prod la Factura y el Estado de Cuenta mostrarán "—" hasta que Luis/Gerencia1 aporten el número (o Contabilidad lo cargue en Cuentas Bancarias). El DV 81 sí queda cubierto por `AdminContFiscalIdentitySeeder` (solo si está vacío). Es acción pendiente explícita, no bug de código. | PENDIENTE de Luis/Gerencia1 |
| INFO-3 | info | Regresión | No ejercitado en runtime: propagación de `quote_item_id` en las tarjetas derivadas (Faltante, Revisión de Inventario, split "mixto") y NC de "Devolución de mercancía" — dependen de flujos de picking/stock; solo los cubre PHPUnit (`DeliveryFlowTest`, `InventoryReviewFlowTest`, `OrderPickingFlowTest`). | NO PROBADO en runtime |

Pre-existentes vistos de pasada, fuera de alcance (no atribuibles a estos cambios): el Libro de facturas excluye la factura anulada pero incluye su NC negativa (documentado en `InvoiceBookService`); `POST /invoices/preview` devuelve `ok:true` para un pedido que ya tiene factura activa (el `store` sí rechaza); el reporte mensual atribuye 1500 a "Cheque" cuando el cobro en cheque fue 1000 + 500 de abono aplicado; el botón de la pantalla dice "+ Crear cliente" (el ticket dice "+ Crear Nuevo Cliente"); voseo en `createError` del ClientPicker.

## SCRUM-935 — Excel de reportes / columna Media

Se abrieron los .xlsx reales con openpyxl y se volcó tipo de celda + `number_format` de cada celda.

| Criterio | Escenario de ruptura intentado | Resultado |
|---|---|---|
| Monto = celda numérica con `$` — Reporte mensual por cliente | Dataset con transferencia/cheque/efectivo/retención; fila de totales; hoja "Pendientes" | OK: todas las columnas de dinero `n` + `$#,##0.00` (Importe, Transferencia, Cheque, Efectivo, Yappy, Otros, Total Caja, Monto pendientes); "Núm." numérico sin formato de moneda |
| Ídem — acumulado | Año/Mes texto/número, totales con celda vacía en "Mes" | OK; misma verificación |
| Ídem — Libro de facturas | NC con negativos (-960.00, -67.20, -1027.20); columna "%" | OK: negativos numéricos con formato de moneda; "%" sigue texto ("7.0%"); totales OK |
| Ídem — Ventas por medio de pago | 9 columnas de método (todas 0 salvo una) | OK: los ceros son celdas `0.0` con formato (no vacías); hoja Pendientes numérica |
| Ceros no desaparecen | Columnas con 0.00 en las 4 exportaciones | OK (`strictNullComparison`) |
| Columna "Media" eliminada (2 reportes) — Excel | Cabeceras y fila de totales | OK: 9 cols (día) / 10 (acumulado), sin "Media" en ninguna |
| Ídem — tabla en pantalla | Playwright, reporte con "Todos los clientes" | OK: TH = FECHA/NÚM./IMPORTE/TRANSFERENCIA/CHEQUE/EFECTIVO/YAPPY/OTROS/TOTAL CAJA (y AÑO/MES en el acumulado); el texto "media" no aparece; fila de totales bien; JSON de la API sin la clave `media` en filas ni totales |
| Ninguna otra columna/dato cambia | Comparación de cabeceras contra el código previo (`git show 0058aae`) | OK: solo se quitó Media; tipo de "Núm." texto->número (INFO-1) |
| Rol sin permiso | designer demo pide los Excel | 403 |

## SCRUM-928

| Criterio | Escenario de ruptura intentado | Resultado |
|---|---|---|
| 1. PDF Factura `RUC <ruc>-81` | `pdftotext` del PDF de F-0001 | OK: `RUC 1838853-1-711641-81`; resto del documento sin cambios |
| 2. DV obligatorio — API `POST /ventas-diseno/clients`, `/clients/{id}/sub-clients`, `/sub-clients` | DV ausente, null, "", "   ", "ab", "123", "8 1", "-1", "1.5", "٣", "８１", entero 81 | OK: 422 `tax_id_dv` en los 3 endpoints; sin DV nunca se crea nada aunque el botón esté habilitado |
| DV con saltos de línea | `"8\n"` / `"\n8"` | 201, guardado como `"8"` (el middleware TrimStrings lo normaliza; no queda `\n` en BD) |
| DV con ceros a la izquierda | `"07"` | OK, se preserva `"07"` y se devuelve en el detalle |
| DV asociado al subcliente correcto | 3 subclientes del mismo cliente con DV 42/07/5 | OK, cada uno con el suyo en `GET /clients/{id}` y en `GET /sub-clients` |
| UI: CRM > Clientes > "+ Crear cliente" | DV junto al RUC (misma fila, a la derecha); botón deshabilitado con todo completo salvo DV; letras/espacios/3 dígitos; doble clic | OK: `spec` (test 2); un solo cliente; DV visible en el detalle ("RUC: … DV: 7") |
| UI: Cotización > modal Cliente Master | Igual + persistencia | OK (test 4) |
| UI: Cotización > modal Subcliente | Master existente + subcliente nuevo, el previo conserva su DV | OK (test 5) |
| UI: Detalle de cliente > "+ Nuevo subcliente" | Igual | OK (test 6) |
| UI: `NewProjectModal` (ClientPicker) | Mini-form RUC+DV; RUC solo no habilita; doble clic | OK (test 7), DV "3" persistido, un solo subcliente |
| UI: `PipelineCardModal` (ClientPicker) | Ídem en tarjeta Lead editable | OK (test 8) |
| Pegado de DV con prefijo | `insertText(' 81')` | FALLA -> PQ-928-1 (spec marcado `test.fail`) |
| 3. Cuenta completa en PDF Factura | Cuenta con `numero_cuenta_completo="04-03-01-123454554"` | OK: `Cuenta número: Banco General 04-03-01-123454554` |
| 3. Sin asteriscos si no hay número | (a) sin ninguna cuenta; (b) cuenta activa sin número; (c) número vacío por API; (d) número `'   '` puesto directo en BD | OK: siempre "—" y `numero_cuenta`/`cuenta_pago` = null; nunca `****4554`. Responsable y "Pago a" intactos (ver PQ-928-3 para el banco en la Factura) |
| 3. Estado de Cuenta detalle/PDF/Excel/pantalla | Con y sin número | OK: número completo, o "—" con "Tipo de cuenta"/"Banco" intactos; sin asteriscos |
| Otros documentos que consumen el bloque | Recibo de pago, NC PDF | Recibo: masked `****4554` (PQ-928-2). NC PDF usa `paymentBlock()` (mismo cambio) |
| Rol sin permiso | designer demo: PDF factura/estado de cuenta 403; POST sub-client sin DV 422 | OK |

## SCRUM-934 — Arqueo de Caja

| Criterio | Escenario de ruptura intentado | Resultado |
|---|---|---|
| Indicadores bajo la tabla y los totales | Pantalla (Real > Hoy) y PDF de resumen | OK: "TOTAL FACTURADO EN EL DÍA" y "TOTAL PASADO A PEDIDO EN EL DÍA" después de "Total neto del día", antes de Ajustes/Retención. PDF: sección "INDICADORES DEL DÍA" |
| No alteran `total_neto` ni la tabla | Crear facturas/pedidos con el arqueo abierto | OK: `total_neto`=4527.20 constante mientras facturado/pedido cambian |
| Estado "al momento de generar" (regenerar acumula) | T1 5027.20/4963.60; aprobar cotización (+111.11 pedido); facturar (+111.11) | OK: 5027.20/4963.60 -> 5027.20/5074.71 -> 5138.31/5074.71 |
| Un día sin movimientos parte en cero | `forDate(2026-09-17)` | 0 / 0 |
| Movimientos de ayer no cuentan | Facturas/pedidos fechados 09-10/11/12 | OK: cada día suma solo lo suyo |
| Factura anulada no cuenta | F-0002 anulada por Corrección de datos | OK excluida (5027.20 no la incluye); ver PQ-934-1 para NC "cancelado" |
| Familia de entregas parciales cuenta UNA vez | Pedido de 3 entregas (963.60) y de 2 entregas (1500) | OK: suma 963.60 y 1500 una sola vez (4963.60 = 1000+963.60+1500+1500) |
| Congelado al cerrar | Cerrar (`POST daily-count/close`), luego aprobar+facturar otra cotización | OK: historial/detalle y PDF siguen 5138.31 / 5074.71; el PDF del cierre coincide |
| Snapshot cerrado sobrevive a anular después | Anular F-0006 tras el cierre | OK: no cambia (arqueo nuevo). Legacy: ver PQ-934-2 |
| Snapshot legado sin indicadores | Fila cerrada 09-11 sin claves | OK: se reconstruye "a la hora del cierre" (20, no 60) |
| Medianoche America/Panama | Documentos 09-10 23:59:30 / 09-11 00:00:30 / 09-11 23:59:59 / 09-12 00:00:00 | OK: 09-10=10, 09-11=60, 09-12=80. Con `Carbon::setTestNow('2026-09-19 03:30 UTC')` (22:30 Panamá): `activeDate`=09-18. `created_at` se guarda en hora Panamá (17:51 con 22:51 UTC) |
| Recargar mitad de flujo | `page.reload()` en Real > Hoy | OK, el estado vive en servidor; misma pantalla |
| Rol sin permiso | designer demo `daily-count` | 403 |

## Regresión B1a / B1b

| Criterio | Escenario | Resultado |
|---|---|---|
| `order_items.quote_item_id` correcto al aprobar | Entrega única (producto+personalizado); parcial 3 fechas con asignación SCRUM-909; producto repetido en 2 partes (entrega única) y (parcial 2 fechas, cada parte a una fecha) | OK en BD: 9 order_items, cada uno apunta al `quote_items.id` con su cantidad/precio (incl. C@250 y C@230 sin mezclarse) |
| `bodega:backfill-order-item-quote-links --dry-run` | (a) con vínculos ya presentes; (b) con los 9 en NULL | (a) "0 vinculados… 0 ambiguos" (idempotente); (b) 9 vinculados, exactamente los ids correctos, sin escribir; verificado que siguieron NULL y luego restauré los valores |
| Entrega única = 1 factura | POST invoices [596] y repetido | 201, luego 422 "ya tiene una factura" |
| Familia de 3 entregas = UNA factura por el total | POST desde la 2ª entrega (598), luego 599 | 1 factura (order_id = raíz 597, `family_root_id`=597, monto total); las hermanas 422 |
| Concurrencia | 4 POST simultáneos sobre la familia S4 | 1 sola factura en BD |
| Anulada + reemplazo conviven | Corrección de datos NC-0001 sobre F-0002 -> F-0005 | OK; F-0005 `order_id`=`family_root_id`=597; sigue rechazando otra factura desde 598/599; detalle y estado de cuenta muestran anulada + NC + reemplazo con saldo 1027.20 |
| Cobros / Abonos / NC / Estado de Cuenta | 4 cobros (transferencia, cheque, efectivo, retención) + confirmar; abono nivel factura + confirmar; NC cancelado + aprobación de Aprobador | Sin regresión. El abono no baja el saldo del estado de cuenta hasta el cobro (diseño SCRUM-902) |

## Spec permanente

`atlanticerp-frontend/e2e/preqa-scrum928-935-batchA-20260918.spec.ts` — 10 tests, autosembrado por API (cliente nuevo por
corrida; cuenta bancaria solo si no hay ninguna activa), baseURL localhost:8090 y se niega a correr contra un host
`atlanticerp.ai` salvo `PREQA_ALLOW_REMOTE=1`. Corrida final: 10/10 (el test "pegar ' 81'" está marcado `test.fail` y
falla como se espera: cuando se corrija PQ-928-1 pasará a rojo y hay que quitar la marca).
Comando: `npx playwright test -c playwright.preqa-local.config.ts e2e/preqa-scrum928-935-batchA-20260918.spec.ts --workers=1`.
Capturas en `atlanticerp-frontend/e2e/__screenshots__/preqa-928-*.png` (directorio sin trackear).

## Datos que quedan en la base local

Clientes/subclientes/proyectos/cotizaciones/pedidos `PQ928 …`, `PQ-A S1…S7` y tarjetas Lead; facturas F-0001…F-0007
(F-0002 anulada por corrección), NC-0001/NC-0002, 4 cobros REC-001…004, 1 abono, 1 cuenta bancaria (id 100, Banco
General, número completo `04-03-01-123454554`), arqueo N°1 del 2026-09-18 cerrado (pendiente de aprobación) más una
fila de arqueo abierta del 2026-09-19 (fixture legado reubicado), y facturas/pedidos de borde de medianoche `FPQ-99100x`
(09-10…09-12) que aparecen en el flujo proyectado. Los comprobantes de NC/abono se subieron al bucket S3 real de
dev (el local está conectado a `atlanticerp-dev`). Los pedidos están forzados a `por_despachar` por SQL para poder facturar.
No corrí `infra/test.sh`, `migrate:fresh` ni ningún `DELETE`.

---

# Re-run tras fixes (SCRUM-928) — 2026-09-18, LOCAL

Fixes probados: frontend `88e658e` (quita `maxLength={2}` del DV en `CreateClientModal` y `ClientPicker`), backend
`8690d90` (etiqueta `tax_id_dv` => "DV" en `lang/es` y `lang/en`) y el spec con el test de pegado ya sin `test.fail`
(`657f6c0`). Ambos repos en `dev` sin cambios sin commitear; B1b está en un stash fuera del árbol (la tabla
`invoices` local ya no tiene `family_root_id`, coherente con lo desplegado).

Estado inicial: la Postgres local estaba recreada y vacía (0 subclientes, 0 cuentas, 0 facturas; `fiscal_settings.dv`=81
por seeder). Se resembró por API/tinker. Verificación de que se sirve el fix: nginx :8090 sirve `index-DvCQly7R.js`
(build de las 18:20, distinto al de la primera pasada) y en los 6 inputs de DV probados el atributo `maxlength` es
`null`. Los tokens de Contabilidad y Aprobador sirven con password = email.

## Veredicto 928: PASADA LIMPIA

PQ-928-1 (pegado trunca el DV) y PQ-928-4 (mensaje "tax id dv") quedaron corregidos y no se encontró nada nuevo
bloqueante en el checklist completo.

## Hallazgos del re-run

| ID | Sev | Estado | Detalle |
|---|---|---|---|
| PQ-928-1 | MEDIO | CORREGIDO | Pegado de `" 81"`, `"-81"`, `"DV 81"`, `"81 "`, `"8 1"` en los 6 flujos: el input queda en `81` y en BD se guarda `81` (ver tabla) |
| PQ-928-4 | BAJO | CORREGIDO | 422 sin DV en los 3 endpoints: "El campo DV es obligatorio." (formato: "El formato del campo DV no es válido."; con `Accept-Language: en`: "The DV field is required.") |
| PQ-928-2 | BAJO | ABIERTO por decisión (ticket aparte, pendiente de confirmación de Luis) | El Recibo de pago PDF sigue con `Cuenta destino: Banco PQ928 — corriente ****4554` (reproducido de nuevo). No bloquea 928 |
| PQ-928-3 | BAJO | ABIERTO por decisión (se deja como está) | Con cuenta activa sin número completo el PDF de Factura muestra "Cuenta número: —" sin nombre de banco; Estado de Cuenta conserva "Banco" |
| OBS-R1 | BAJO (nuevo, no de 928) | reproducido 2 veces de ~50 intentos | `ClientPicker` de Cliente Master en `NewProjectModal`: si el primer `search('')` (al enfocar) responde DESPUÉS del `search(nombre)`, el desplegable queda con la lista SIN filtrar y la opción tipeada no aparece (captura vista al fallar el helper del test). Código de `runSearch` no fue tocado por 928 (resultados de una búsqueda vieja pisan a la nueva). Un usuario que teclea despacio no lo ve |
| OBS-R2 | info | comportamiento | `"8 1"` (pegado o tecleado) se normaliza a `81`: el filtro `\D` quita el espacio y junta los dígitos. Por API directo `"8 1"` sigue siendo 422 (regex `^\d{1,2}$`, sin normalizar). No es un defecto: es lo que hace el filtro para `" 81"`/`"81 "` |

Sigue pendiente (no es bug de código): el número de cuenta completo real no está sembrado (`ADMIN_CONTAB_BANK_ACCOUNT_NUMBER`);
mientras nadie lo cargue, Factura y Estado de Cuenta muestran "—" (ver INFO-2 arriba).

## Punto 2 — pegado real de DV en CADA flujo de alta

Método: portapapeles real (`navigator.clipboard.writeText` + `Ctrl/Cmd+V`), además de `keyboard.insertText` y tecleo
real (`pressSequentially`). Para cada valor se guardó el formulario con el valor pegado (5 valores x 6 flujos = 30
altas, todas con el botón habilitado) y se consultó la BD (`sub_clients.tax_id_dv`, con corchetes para ver espacios).

| Flujo (UI real) | `" 81"` | `"-81"` | `"DV 81"` | `"81 "` | `"8 1"` | Input (portapapeles / insertText / tecleo) | BD |
|---|---|---|---|---|---|---|---|
| CRM > Clientes > "+ Crear cliente" | OK | OK | OK | OK | OK | `81` / `81` / `81` en los 5 | `[81]` en todos |
| Cotización > modal Cliente Master | OK | OK | OK | OK | OK | ídem | `[81]` en todos |
| Cotización > modal Subcliente (master existente) | OK | OK | OK | OK | OK | ídem | `[81]` en todos |
| Detalle de cliente > "+ Nuevo subcliente" | OK | OK | OK | OK | OK | ídem | `[81]` en todos |
| Pipeline > Nuevo Proyecto (mini-form `ClientPicker`) | OK | OK | OK | OK | OK | ídem | `[81]` en todos |
| Pipeline > tarjeta Lead (mini-form `ClientPicker`) | OK | OK | OK | OK | OK | ídem | `[81]` en todos |

Detalle de "qué pasa con `8 1`": en los 6 flujos el input pasa a `81` y se guarda `81`. En BD las 80 altas `R-*` de la
batería (varias corridas) tienen `tax_id_dv` de exactamente 2 caracteres `81`, ningún espacio ni carácter extra.

## Punto 2 — validación y mensaje del 422 (API directo)

| Endpoint | DV ausente / null / "" / "   " | "ab" / "123" | Filas creadas |
|---|---|---|---|
| `POST /ventas-diseno/clients` | 422 "El campo DV es obligatorio." | 422 "El formato del campo DV no es válido." | 0 |
| `POST /ventas-diseno/clients/{id}/sub-clients` | ídem | ídem | 0 |
| `POST /ventas-diseno/sub-clients` | ídem | ídem | 0 |

Además el spec permanente sigue cubriendo por API `"8 1"`, `"-1"`, `"1.5"`, `"٣"` y el entero 81 (todos 422), `"07"`
(se preserva) y que cada subcliente conserva su propio DV.

## Punto 1 — PDF de Factura con `RUC <ruc>-81`

Facturas F-0001 (entrega única) y F-0002 (familia de 3 entregas parciales, una sola factura) generadas por API:
`RUC 1838853-1-711641-81` en ambos PDF; el resto del documento (cliente, cotización, ítems, subtotal/ITBMS/total,
"Datos de pago", pie) sin cambios.

## Punto 3 — número de cuenta sin máscara

| Escenario | Factura PDF | Estado de Cuenta (pantalla / PDF / Excel / API) |
|---|---|---|
| Cuenta activa SIN número completo | "Cuenta número: —" | numero_cuenta `null` / "Cuenta número: —"; "Tipo de cuenta" y "Banco" intactos |
| Número completo = `'   '` puesto directo en BD | "—" | `null` (mismo resultado) |
| Sin cuenta activa (desactivada por API y reactivada) | (API detail: `cuenta_pago` null) | banco/tipo/numero null, responsable y "Pago a" intactos |
| Número completo `0403011234-54554` | `Cuenta número: Banco PQ928 0403011234-54554` (ambas facturas) | `0403011234-54554` en pantalla, PDF, Excel y API; sin asteriscos |
| Otros documentos | Recibo de pago: masked (PQ-928-2, decisión); NC PDF: no muestra el bloque de pago | — |

Nunca apareció `****XXXX` en Factura ni en Estado de Cuenta.

## Smoke 934 / 935

Arqueo: `daily-count` devuelve `total_facturado_dia` 1963.60 y `total_pasado_a_pedido_dia` 1963.60 (F-0001 + F-0002 /
los dos pedidos raíz creados hoy, la familia una sola vez), `total_neto` 0. Libro de facturas: Excel con celdas numéricas
`$#,##0.00`. Reporte mensual por cliente: sin "media" en la respuesta.

## Spec permanente

`e2e/preqa-scrum928-935-batchA-20260918.spec.ts`: **10/10** (test de pegado ya normal, cubre `" 81"`, `"-81"`, `"DV 81"`).
La batería de portapapeles por flujo (`paste-battery.spec.ts`) es temporal y quedó solo en el scratchpad de la sesión;
sus resultados están en la tabla de arriba.

## Datos que quedan en la base local (esta pasada)

Estado inicial vacío. Ahora hay: ~165 subclientes / 106 clientes maestros de prueba `PQ928 …`, `PQ928R …` y `PQ-A R1/R2`
(80 subclientes `R-*` de la batería de pegado, todos con DV 81; el resto con DV 42/9/3/7 de los tests), tarjetas Lead
`PQ928 …`, 2 cotizaciones aprobadas con sus pedidos (1 entrega única y 1 familia de 3, forzados a `por_despachar` por
SQL), facturas F-0001 y F-0002, 1 cobro REC-001 (confirmación pendiente), 1 NC-0001 (`cancelado`, pendiente de
aprobación de Aprobador), 1 cuenta bancaria id 100 (Banco PQ928, número completo `0403011234-54554`, activa) creada por el
spec, y el arqueo del 2026-09-18 abierto. El comprobante de la NC se subió al bucket S3 real de dev. No se corrió
`infra/test.sh`, `migrate:fresh`, `DELETE`, commit, push, checkout ni stash, y no se tocó Jira.
