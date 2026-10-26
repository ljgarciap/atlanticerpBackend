# Spec — Fase 4: dataset demo genérico y datos del cliente fuera del seed por defecto

**Fecha**: 2026-10-25 · **Estado**: decisiones de §7 RESPONDIDAS por Luis (2026-10-25); pendiente su «adelante» para implementar la entrega 4a.
**Contexto**: decisión de Luis (2026-10-24, `docs/analisis/aislamiento-nucleo-2026-10-24.md` §9): ERP genérico multi-sector; la Fase 4 reemplaza los datos de ejemplo del cliente
por un dataset demo genérico («sin iluminación ni personas» en seeders, e2e e i18n). Fases 1-3 ya mezcladas (permisos por permiso, `role_id` único, catálogo de roles genérico).

## 1. Problema
Hoy `php artisan db:seed` mezcla **tres cosas distintas** y no las distingue por entorno:
1. **Bootstrap neutro** (roles, niveles, áreas, ajustes): correcto y necesario en todos los entornos.
2. **Datos demo** con vocabulario y nombres del cliente anterior (proyectos «[DEMO] Torre Delta», productos de iluminación, marcas, showroom): solo deberían existir en local/dev/testing.
3. **Datos reales del cliente anterior**, que hoy se cargan **también en producción**:
   - `AdminContFiscalIdentitySeeder`: razón social, **RUC, DV y dirección fiscal** del cliente.
   - `AdminContBankAccountSeeder`: **4 cuentas bancarias reales**, una con el **número completo** (`03-01-01-…`). Se crean «solo si la tabla está vacía (el caso de `prod`)».
   - `ZonaLibreProviderSeeder` y `LafayetteLightingProviderSeeder` (proveedores reales), `SharedFamiliesSeeder` (familias de marcas de iluminación), `CatalogUatTestDataSeeder` («pedida en Producción para UAT») y `AlmacenEquivalenciaSeeder` (mapeo del ERP legado ICG).

Consecuencia: un cliente nuevo que despliega el producto **nace con la identidad fiscal, las cuentas bancarias y el catálogo de otra empresa**. Además esos datos están **versionados en git** (y en su historia).

## 2. Alcance medido (sobre `master`, 2026-10-25)
**Seeders**: 39 archivos, ~5.150 líneas.

| Clase | Seeders | Qué hacer |
|---|---|---|
| **A. Núcleo neutro** (se queda) | `Tenant`, `SecurityLevel`, `Department`, `CoreUser`, `BusinessRoleUser`, `RoleBackfill`, `PrimaryApproverBackfill` (x2), `AdminContRoleVisibilityBackfill`, `SpecialPermission` (vacío), `UserModuleVisibilityGrant`, `GlobalSettings`, `AiModelPricing`, `VentasDisenoSalesGoals` | Sin cambio funcional; solo sus identidades (§3.4) |
| **B. Datos del cliente** (sale del seed por defecto) | `AdminContFiscalIdentity`, `AdminContBankAccount`, `ZonaLibreProvider`, `LafayetteLightingProvider`, `SharedFamilies`, `CatalogUatTestData`, `AlmacenEquivalencia` | Ya no corren por defecto; ver §3.1 |
| **C. Demo** (se reemplaza por dataset genérico) | `CrmDemo`, `VentasDisenoDemo`, `ServiciosDemo`, `CatalogProduct`, `CatalogProductFamily`, `DemoProviderAssignment`, `DemoReplacementRequest`, `ProductWarehouseStockBackfill`, `InternalTechnician`, `WarehouseAssistantMapping`, `AuthUser` | Mismo *tamaño y forma*, contenido neutro (§3.2) |
| **D. Obsoletos** (se borran) | `Scrum171AgendaBodegaHoy`, `Scrum416420RotationVerification`, `Scrum711SalesForceDemo`, `Scrum831/845InventoryTestData`, `QaTicketsBatch20260725` | Data de pruebas de tickets Jira puntuales; 3 de 6 no tienen ni un llamador y los otros 3 solo los usan un test o comandos de QA (que se retiran con ellos) |

**Acoplamiento**: los seeders de demo **corren en `testing`** (CI), así que ~25 archivos de test dependen de su contenido; vocabulario de iluminación en **98 archivos de test** (441 menciones),
24 de `app/` (54), 9 de seeders (61), frontend `src` 59 archivos / `i18n` 9 / e2e 20. «[DEMO] Torre …»: 7 archivos / 33 menciones.
**Identidades**: 35 emails `@test.com` + 8 `@atlantic.test`. `designer@atlantic.test` aparece en **188 archivos de test** (es el «usuario común» de la suite).
Familias de email derivadas de roles ya renombrados en la Fase 3: `vendedordisenador*` (BE 28 / FE 43 archivos), `tecnicoservicios*` (23 / 26), `ayudantegeneralbodega*` (12 / 5), `garantiasservicios*` (10 / 6).
**Prompts de IA** con sector fijo («empresa de diseño y suministro de iluminación»): 4 en `app/`.

## 3. Diseño propuesto
### 3.1 `DatabaseSeeder` en tres niveles + datos del cliente fuera
- **Núcleo** (clase A): corre siempre. Nada con nombre de empresa, persona ni sector.
- **Demo** (clase C): solo `local`/`dev`/`testing`, como hoy, pero con el dataset genérico de §3.2.
- **Datos del cliente** (clase B): **dejan de llamarse desde `DatabaseSeeder`**. Un tenant nuevo arranca con identidad fiscal, cuentas bancarias y proveedores **vacíos**
  (las pantallas ya permiten capturarlos; `AdminContBankAccountSeeder` ya solo rellenaba si estaba vacío). Los seeders de B se retiran del repo junto con Legacy/Lafayette en la Fase 5
  (la integración Lafayette y el ERP ICG son lo que los hace necesarios); hasta entonces quedan **sin llamador** y con los **valores sensibles reemplazados por placeholders** (§3.3).
- Se elimina la rama «se salta en `testing`» de varios seeders: pasa a ser regla del `DatabaseSeeder`, un solo lugar.
- Test de contrato: `DatabaseSeeder` en entorno `production` crea solo clase A (ningún proveedor, cuenta, producto ni proyecto).

### 3.2 Dataset demo genérico — **misma forma, contenido neutro**
Es lo que evita reescribir 25 archivos de test: se conservan cantidades, relaciones y estados (los mismos N clientes, M proyectos en las mismas etapas, productos con y sin stock, etc.) y solo cambian los nombres.
| Hoy | Genérico |
|---|---|
| `[DEMO] Torre Delta Fase 1`, `Centro Comercial Westland`, `Hotel Decameron…` | `[DEMO] Proyecto Alfa`, `[DEMO] Proyecto Beta`… |
| `Grupo Constructor Delta`, `Inversiones Costa Bella` | `[DEMO] Cliente Maestro A/B`, subclientes `[DEMO] Cliente A1`… |
| Productos `IL-DEC-1410A · 770 Lights`, familias de marcas de iluminación | `DEMO-001 · Producto demo 1`, familias `Familia demo A/B` (categorías neutras) |
| Contactos con correos de empresas reales (`@grupopacifico.com`, `@westland.com.pa`) | `contacto1@demo.test` |
| Obras de Servicios («Torre Business Park») | `[DEMO] Sitio 1` |
Los textos demo salen de un único archivo de constantes (`DemoDataset`), no repartidos por seeder.

### 3.3 Datos sensibles del cliente en el repositorio
RUC, dirección fiscal y **número de cuenta bancaria completo** están en archivos versionados. Se reemplazan por placeholders obvios (`000-0-000000`, `00-00-00-000000-0`) en la misma fase.
**El historial de git los conserva**: purgarlo exige reescribir historia y forzar push de `master` en ambos repos (operación destructiva, aparte). Ver decisión 2 de §7.

### 3.4 Identidades
- Renombrar las 4 familias de email derivadas de roles renombrados en la Fase 3 y sus nombres visibles: `vendedordisenador[N]@test.com → vendedor[N]@test.com` («Vendedor Test N»), `tecnicoservicios[N] → tecnico[N]`,
  `garantiasservicios → tecnicogarantias`, `ayudantegeneralbodega[N] → auxiliarbodega[N]`. Reemplazo exacto y mecánico, como en la Fase 3; el fixture de identidades del frontend se regenera.
- `designer@atlantic.test → vendedor@atlantic.test` (188 archivos de test; mecánico). Las cuentas `supervisor@`, `electrician@`, `readonly@`, `multi@` (fixtures de niveles sin rol real; `electrician` es vocabulario del oficio)
  → `nivel5@`, `nivel2@`, `nivel1@`, `nivel8@`. `management@`, `superadmin@`, `gerencia@`, `contabilidad@`, etc. ya son genéricos y no cambian.
- Dominios `@test.com`/`@atlantic.test`: se conservan (son del entorno de pruebas, no de personas).

### 3.5 Vocabulario del sector en prompts, i18n y e2e
- **Prompts de IA**: la descripción del negocio sale del texto fijo y pasa a un ajuste (`business_description`, por defecto neutro: «empresa»); los 4 prompts la usan. Editable por superadmin sin deploy.
- **e2e y tests**: los que afirman nombres demo se actualizan con el dataset nuevo (§3.2) por reemplazo exacto.
- **Fuera de esta fase** (porque son configuración o integración, no dato demo): categorías del catálogo de Lafayette (`LAMPARAS`…) → Fase 5; nombres de bodegas operativas, tipos de ticket y etiquetas de sector en i18n (`showroom`, `iluminacion_techo`) → Fase 7.

## 4. Fuera de alcance
Integración Lafayette/ICG y Legacy (Fase 5), fiscal Panamá/moneda (Fase 6), catálogos configurables (Fase 7), renombrar módulos, y los nombres de bodegas (`Warehouse::operativas`).

## 5. Criterios de aceptación
1. `db:seed` en `production` crea **solo** clase A: cero proveedores, cuentas bancarias, identidad fiscal, productos o proyectos (test automático sobre el estado de la BD).
2. En `local`/`dev`/`testing` el dataset demo tiene **la misma forma** que hoy (mismos conteos por tabla/estado) y cero términos de iluminación ni nombres de empresas o personas (test con lista de términos prohibidos sobre las tablas sembradas).
3. Ningún archivo versionado fuera de las migraciones históricas contiene el RUC, la dirección fiscal ni los números de cuenta reales (test que escanea el repo con esos valores).
4. Los seeders obsoletos (clase D) y los comandos que solo los usaban se eliminan sin romper `artisan list` ni tests.
5. Las 4 familias de email y `designer@` renombradas en backend, frontend, e2e y fixture; el test de equivalencia de la Fase 1b sigue verde.
6. Los prompts de IA no mencionan el sector y toman `business_description`.
7. PHPStan L8 limpio; suites completas de backend y frontend en verde; Playwright CLI: login con un Vendedor, un Técnico y un Auxiliar de Bodega y comprobación visual de que el demo no muestra vocabulario del cliente anterior.

## 6. Entrega (tres entregas apiladas, backend y frontend en cada una)
- **4a — Seeds por niveles y datos del cliente**: `DatabaseSeeder`, clase B fuera, clase D borrada, placeholders (§3.1, §3.3). Bajo riesgo, alto valor (corta la carga de datos ajenos en producción).
- **4b — Dataset demo genérico** (§3.2) + ajuste de los ~25 tests acoplados y e2e.
- **4c — Identidades y prompts** (§3.4, §3.5): renombre mecánico.
Cada entrega: spec aprobada → Senior Review → merge, como en las fases anteriores.

## 7. Decisiones (respondidas por Luis el 2026-10-25: las recomendadas, salvo la 2)
1. **Datos del cliente (clase B)**: sin llamador + placeholders; se borran con Legacy/Lafayette en la Fase 5. ✅ recomendado
2. **Historial de git**: **solo limpiar desde ahora**. RUC, dirección fiscal y la cuenta bancaria completa **seguirán visibles en commits antiguos** de ambos repos; se acepta. (Reabrir si resulta que los repos no son privados o esos datos son sensibles.)
3. **Identidades**: renombrar todo (4 familias de roles, `designer@`, cuentas de niveles). ✅ recomendado
4. **Prompts de IA**: ajuste editable `business_description` con valor neutro por defecto. ✅ recomendado
5. **Entrega**: tres entregas 4a/4b/4c. ✅ recomendado

## 8. Riesgos
- Cambiar el dataset demo toca ~25 archivos de test: se mitiga manteniendo la forma (§3.2) y verificando con la suite completa.
- Quitar la clase B del seed por defecto cambia lo que ve un entorno ya desplegado **solo si se vuelve a sembrar**; los datos ya cargados (en `dev`/`test`) no se tocan.
- El renombre de emails afecta ~190 archivos de test y ~140 e2e: mecánico, con el mismo método de la Fase 3.

## 9. Resultado de la entrega 4a (2026-10-25)

Ramas `fase4a/seeds-por-niveles` (backend) y `fase4a/datos-cliente-fuera` (frontend). Decisiones de §7 aplicadas.

**Hecho**
- `DatabaseSeeder` en tres niveles (núcleo siempre / demo solo local-dev-testing / datos del cliente nunca por defecto). Quedan **sin llamador** y con valores de ejemplo: `AdminContFiscalIdentity`, `AdminContBankAccount`,
  `ZonaLibreProvider`, `LafayetteLightingProvider`, `SharedFamilies`, `CatalogUatTestData`, `AlmacenEquivalencia`. La base de pruebas carga la identidad fiscal de ejemplo y el proveedor de Zona Libre vía `tests/Support/TestDatabaseSeeder`.
- Borrados 6 seeders de pruebas de tickets Jira (`Scrum171/416420/711/831/845`, `QaTicketsBatch20260725`), el comando `qa:fix-demo-fiscal-data` y sus tests.
- **PDF de cotizaciones** (Ventas & Diseño y Servicios): el cheque a nombre de la razón social y la cuenta ACH ya no están escritos en las plantillas; salen de los datos de la empresa (`QuotePaymentInstructions`: razón social del perfil fiscal + primera cuenta corriente activa con número
  completo) y **si no hay datos no se imprime el bloque**. Los estimadores de maquetación usan esas mismas líneas. Pie de página: respaldo neutro («Atlantic») sin dirección ni sitio web; las cotizaciones leen el pie de la empresa vigente.
- **Cobros**: la cuenta por defecto (`PaymentService`, `ArchitectCommissionService`) ya no se elige por nombre de banco (tarjeta → «Banistmo», resto → «Banco General»), sino por la primera cuenta activa del tipo correcto.
- Frontend: se quitan de i18n `document.achAccount/companyName/companyFooter` (cuenta, teléfonos, correo y web del cliente; no se usaban); tests y e2e con valores de ejemplo; se elimina un spec pre-QA que afirmaba el pie del cliente.
- Contratos: `DatabaseSeederTiersTest` (estático + corrida real en entorno `production`; verificado con una mutación), `ClientDataInRepoTest` (backend) y `clientDataInRepo.test.ts` (frontend).

**Verificación**: PHPStan 0 errores; suite completa del backend 6188 tests, 0 fallos (la primera corrida dio 7 errores en `QuotePdfSignatureTotalsRenderTest` por consultar la BD sin tenant; corregido); frontend `tsc` limpio y 248 archivos / 3112 tests.

**Hallazgos (no cambiados)**
1. **El emisor del sandbox del PAC** (RUC, DV y razón social del cliente) sigue en `SandboxFiscalFixtures`, `infra/scripts/msl-polling-202.php` y 4 tests fiscales: es el emisor registrado en el sandbox y no puede ser un placeholder sin romper esa integración → **Fase 6** (el test de contrato los lista como excepción explícita).
2. **El núcleo siembra cuentas de usuario por defecto en TODOS los entornos, producción incluida**: `CoreUserSeeder`/`BusinessRoleUserSeeder` crean `superadmin@test.com`, `gerencia@test.com`… con contraseña igual al correo. Un despliegue real necesita un alta inicial de superadmin y no estas cuentas → **propuesta de entrega aparte** (§7 decisión pendiente).
3. `LafayetteMapearProveedorCommand::PROVEEDOR` fija el proveedor «BK INTERPRISES S.A.» y `SandboxFiscalFixtures`/familias de iluminación siguen en el código → Fase 5.
4. El RUC, la dirección y la cuenta bancaria completa **siguen en el historial de git** y en `docs/` (reportes históricos de QA): aceptado por Luis («solo limpiar desde ahora»).
