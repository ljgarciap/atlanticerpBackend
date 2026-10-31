# Fase 15a — Un solo concepto de cobros

**Estado**: decisiones de §5 tomadas por Luis (2026-10-30); «adelante con 15a» dado; **15a-1 (código) implementada** (§8), pendiente de mezclar; 15a-2 (esquema) pendiente. Origen: candidato 4.1 de `docs/flujos/candidatos-priorizados.md` («`cobros_v2_activo` nace apagado y los abonos heredados siguen en el código»). No se implementa sin su «adelante»; el borrado de tablas necesita además su confirmación explícita.

## 1. Objetivo
Hoy conviven DOS modelos de cobros alternados por una bandera, y el que nace activo es el viejo. Dejar uno solo (el nuevo: cobro con o sin factura, aplicación decidida por el usuario, compensaciones) y retirar la bandera, los abonos heredados y la maquinaria de transición.

## 2. Hallazgos (medidos sobre `master`)
- **Dos modelos, uno a la vez**, según `admin_contab_settings.cobros_v2_activo` (`CobrosConfigService`, comando `php artisan cobros:v2 on|off`, nace **apagado**). Apagado: cobro ligado a facturas (`POST /payments`) y **abonos** (`/abonos`, con su recibo, folio y adjuntos). Encendido: `POST /payments/cobro`, compensaciones y saldo a favor derivado del libro (`payment_movements`). Con uno activo, el otro responde 422.
- **El frontend lo sabe por el JWT** (`flags.cobros_v2`): cambia el menú (Abonos/Saldo a favor ↔ Compensaciones) y el formulario de cobro (`RegistrarCobroModal` ↔ `RegistrarCobroV2Modal`).
- **Maquinaria de transición** que solo existe por la convivencia: escritura en sombra del libro (`PaymentLedger::shadow`), `PaymentLedgerBackfill`/`PaymentLedgerVerifier` y los comandos `cobros:backfill-ledger` y `cobros:verify-ledger`.
- **Código del modelo anterior**: `PaymentService::register` (el servicio tiene 930 líneas), `AbonoService` (768), `AdminContAbonoController`, `AbonoReceiptPdfService`, adjuntos y contador de folios de abono, tres `FormRequest`; ~40 archivos del backend mencionan abonos (estado de cuenta, comisiones, notas de crédito, reemplazo de factura, facturación electrónica…), 17 tests; en el frontend 19 archivos (`RegistrarCobroModal`, `RegistrarAbonoModal`, `AbonosPage`, `SaldoFavorPage`, menú, tipos, API) y 12 tests.
- **Datos locales**: 0 abonos, 0 cobros del modelo anterior, 58 cobros del nuevo. **Atlantic no está desplegado**, así que no hay datos reales del modelo anterior que conservar.
- **Riesgo real**: otros módulos leen abonos y cobros anteriores; hay que quitar cada rama sin tocar los cálculos del modelo nuevo.

## 3. Diseño propuesto (dos pasos, como la Fase 14f)
- **15a-1 — Código (reversible con `git revert`)**: el modelo nuevo pasa a ser el único. Fuera `cobros_v2_activo` (ajuste, servicio de configuración, comando `cobros:v2`, claim del JWT, `GET /payments/config`), `POST /payments` y `/abonos`, `AbonoService`, recibo/adjuntos/folios de abono y la escritura en sombra. **Se CONSERVAN `PaymentLedgerBackfill`, `PaymentLedgerVerifier` y los comandos `cobros:backfill-ledger` y `cobros:verify-ledger`** (decisión 2): son el camino para convertir los datos de un entorno que los tenga, y con ellos los modelos de lectura de los abonos y de los cobros anteriores; en el frontend, fuera `RegistrarCobroModal`, `RegistrarAbonoModal`, `AbonosPage`, `SaldoFavorPage` (si el modelo nuevo no la usa) y las ramas por bandera del menú. Los cobros del modelo nuevo no cambian.
- **15a-2 — Esquema (IRREVERSIBLE)**: migración que borra `abonos` (con sus folios y adjuntos), la columna `payments.cobros_v2` y la clave del ajuste, **solo en un entorno donde no queden abonos ni cobros del modelo anterior sin convertir**. Guarda: si los hay, aborta e indica correr `cobros:backfill-ledger` y `cobros:verify-ledger` (que debe cuadrar) antes; respaldo obligatorio. Como los movimientos del libro pueden referenciar un abono, la tabla solo se borra cuando está vacía o ya convertida y sin referencias; si no, la migración aborta sin borrar nada. Cuando todos los entornos estén convertidos, un paso posterior retira el backfill y el verificador.
- **Contrato**: tests de equivalencia del estado de cuenta, el saldo del cliente y las comisiones antes/después con datos del modelo nuevo.

## 4. Criterios de aceptación
1. No queda código, ruta, texto, ajuste ni comando que distinga «cobros v2»; el contrato lo verifica.
2. Registrar un cobro (con o sin factura), compensar, anular y consultar el estado de cuenta y el saldo funcionan igual que con la bandera encendida (flujos `e2e:flujos` de Admin & Contab en verde).
3. Un tenant nuevo nace con el modelo nuevo, sin ningún comando previo.
4. Un entorno con abonos y cobros del modelo anterior los convierte con el backfill y el verificador cuadra; la migración de esquema aborta antes de eso.
5. Facturación electrónica, notas de crédito, comisiones y reemplazo de factura dan los mismos resultados (tests de equivalencia).
6. La migración aborta sin borrar nada si quedan datos del modelo anterior sin convertir.
7. PHPStan nivel 8, `tsc`, suites completas en verde.

## 5. Decisiones de Luis (2026-10-30)
1. **Modelo anterior**: se **retira del todo** (bandera, creación de abonos y de cobros del modelo viejo, maquinaria de sombra). ✅
2. **Entornos con datos del modelo anterior**: se **convierten con el backfill existente**, que por eso se conserva junto al verificador y sus comandos hasta que todos los entornos estén convertidos. ✅
3. **Entrega**: **dos pasos apilados** (15a-1 código, 15a-2 esquema), el segundo con su confirmación explícita del borrado. ✅

## 6. Riesgos
- Toca dinero: por eso la equivalencia de cálculos antes/después es criterio de aceptación, no un extra.
- Se quita la escritura en sombra pero se conservan el backfill y el verificador: son la red de seguridad de la conversión. Hay que probar el camino completo (cobro viejo → backfill → verificador cuadra → migración de esquema) con datos sembrados en un test.

## 7. No-alcance
Cambiar cómo se calculan comisiones, notas de crédito o facturación; el modelo de compensaciones; y los candidatos 4.2 a 4.7 (cada uno su propia entrega).

## 8. Resultado de 15a-1 (código)
- **Backend**: fuera la bandera (`cobros_v2_activo`, `CobrosConfigService::v2Activo/setV2Activo`, comando `cobros:v2`, claim `cobros_v2` del JWT, `v2` de `GET /payments/config`); fuera `POST /payments` (registro ligado a facturas) con su `FormRequest` y `PaymentService::register`; fuera todo `/abonos` (controlador, rutas de cascada, recibo en PDF, adjuntos, folios, dos `FormRequest`) y los métodos de gestión de `AbonoService` (queda solo lo que el cálculo de saldo y las anulaciones aún usan para los datos antiguos). Compensaciones y `POST /payments/cobro` responden sin ningún encendido previo.
- **Se CONSERVA** (decisión 2): `PaymentLedgerBackfill`, `PaymentLedgerVerifier`, sus comandos, los modelos de lectura de abonos y de cobros anteriores, y las ramas por cobro (`payments.cobros_v2`) que tratan los datos antiguos. Se retiran en un paso posterior, cuando todo entorno esté convertido; la columna y las tablas, en 15a-2.
- **Frontend**: fuera `RegistrarCobroModal`, `RegistrarAbonoModal`, `AbonosPage`, `SaldoFavorPage` (y sus rutas), la sección de abonos del detalle de cobro y del estado de cuenta, las ramas por bandera del menú y de `CobrosPage` (siempre el formulario con o sin factura), el flag `cobros_v2` del tipo de sesión, los métodos y hooks de abonos y saldo a favor y sus textos.
- **Pruebas**: se eliminaron o recortaron los tests que probaban solo la semántica del modelo anterior (abonos, saldo a favor aplicado en el registro, bandera apagada); los genéricos de cobros (recibo en PDF, historial, adjuntos, confirmación) siguen vivos mediante el adaptador `RegistraCobrosConCargaAnterior`, que traduce la carga antigua al registro nuevo; la prueba de concurrencia pasó a `registerV2`. Nuevo `UnSoloConceptoDeCobrosTest` (rutas retiradas, comando inexistente, configuración sin `v2`, login sin la bandera).
- **Cobertura que se pierde**: las ramas de lectura de datos antiguos (anular o confirmar un cobro del modelo anterior con abonos) ya no tienen un test que cree su dato por la API; solo se ejercen vía el backfill. Es el motivo para retirarlas en cuanto no queden entornos sin convertir.

## 9. Resultado de 15a-2 (esquema y retiro total)
Decisión de Luis (2026-10-30): **retirar todo, incluido el backfill**. Un entorno con datos del modelo anterior debe convertirse con la versión previa ANTES de actualizar.
- **Código fuera**: `AbonoService`, `AdminContAbono` y su contador de folios, `AbonoStateException`, `PaymentLedgerBackfill` y `cobros:backfill-ledger`, la escritura en sombra (`PaymentLedger::shadow`), el endpoint y servicio de «saldo a favor» por abonos (`SaldoFavorService`, `GET /saldo-favor`) y las ramas de abono en anulación, compensación, estado de cuenta y devolución. `ClientBalanceService` pasa a ser la única fuente del saldo a favor de notas de crédito (`saldoNotasDisponible`, `saldoNotasPorCliente`, `desglose`).
- **Migración `2026_11_09_100000_retire_abonos_schema` (IRREVERSIBLE, `down()` lanza error)**: borra las tablas `abonos` y `abono_folio_counters`, las columnas `payment_invoices.abono_id`, `payment_invoices.monto_abono_aplicado`, `payment_movements.abono_id` y `payments.cobros_v2`, las líneas de compensación y solicitudes de reverso con origen `abono`, y el ajuste `cobros_v2_activo`. Fuera de local/dev/testing **aborta** si quedan abonos, cobros con `cobros_v2 = false` o líneas de compensación de origen abono. **Respaldo obligatorio antes de migrar un entorno con datos.**
- La migración histórica `2026_10_19_100100` (backfill del libro) quedó sin efecto, porque la clase que llamaba ya no existe.
- **Se conserva** `PaymentLedgerVerifier` (y `cobros:verify-ledger`), reducido a comparar la disponibilidad del libro con los movimientos ligados a cobros; la clave `abonos` de su informe se llama ahora `cobros`.
- **Residuo conocido**: la columna `payments.saldo_favor_aplicado` sigue existiendo (siempre 0 en cobros nuevos); se retira cuando se decida tocar el recibo.
- **Frontend**: fuera tipos `Abono*`, `SaldoFavor*`, `cobros_v2`, `abonos`, `monto_abono_aplicado`, `abono_obligatorio` y el origen `abono` en Compensaciones y estado de cuenta; el pipeline ya no pregunta «¿registrar abono?» al pasar a pedido (navegaba a una ruta inexistente).
- **Pruebas**: se quitaron los tests de lectura de datos del modelo anterior (backfill, abonos históricos) y se reescribieron con cobros/notas los que verificaban comportamientos aún vigentes (saldo a favor en facturas, cruces, sugerencia de compensación, estado de cuenta). Nuevo `RetiroAbonosMigracionTest`.
