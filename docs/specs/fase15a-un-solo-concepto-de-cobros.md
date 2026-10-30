# Fase 15a — Un solo concepto de cobros

**Estado**: decisiones de §5 tomadas por Luis (2026-10-30); pendiente de su «adelante con 15a». Origen: candidato 4.1 de `docs/flujos/candidatos-priorizados.md` («`cobros_v2_activo` nace apagado y los abonos heredados siguen en el código»). No se implementa sin su «adelante»; el borrado de tablas necesita además su confirmación explícita.

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
