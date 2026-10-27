# Spec — Fase 6: fiscal por país (Panamá como el primer paquete)

**Estado**: 6a y 6b MEZCLADAS (§7-§8). 6c IMPLEMENTADA (§9), pendiente de merge. 6d pendiente (con su «adelante»).
**Origen**: decisión 2 del análisis de aislamiento (multi-país: «el fiscal de Panamá pasa a ser un paquete más sobre `PacProviderContract`») y paso 6 del plan de 8 fases.

## 1. Hechos verificados (2026-10-26)
**Lo que ya está bien diseñado**
- Existe el **puerto** `PacProviderContract` (`certificarFactura`, `certificarNotaCredito`, `consultarEstado`, `validarReceptor`) y `PacProviderFactory`, que elige el adaptador según `fiscal_settings.pac_provider`.
  Facturación, notas de crédito y las pantallas de CUFE/QR hablan solo con esa interfaz.
- Las tasas de impuesto ya son **datos**: tabla `itbms_rates` (7 % general, 3,5 % retención, 0 % exento), con CRUD; y la exención por cliente (`itbms_exento`) es una marca de cliente.

**Lo que está atado a Panamá** (cotas altas, backend `app/`, archivos con al menos una mención)
- ITBMS 62 · RUC/DV 59 · DGI 41 · PAC 60 · CUFE 19 · Digifact/MSL 41 · `America/Panama` 21. Se concentra en `AdminCont/FacturacionElectronica` (36 archivos), `AdminCont/Services`, `AdminCont/Http`; el resto en VentasDiseno (cotización: exención), Compras (proveedores: RUC+DV) y Servicios.
- **Un solo adaptador está cableado**: `PacProviderFactory::make()` solo conoce `'msl'`; `DigifactPacAdapter` existe pero la fábrica lo rechaza, y la base local tiene `pac_provider = 'digifact'` (inconsistencia ya presente: probablemente se rompe al certificar).
- `config/fiscal-pdf.php` fija los hosts permitidos de la DGI (`dgi-fep.mef.gob.pa`). `fiscal_settings` guarda RUC/DV, régimen tributario y `serie_fiscal` (hoy `ILU`, prefijo del cliente anterior).
- **Zona horaria**: `config('app.timezone')` por defecto `America/Panama` y 7 constantes `BUSINESS_TIMEZONE`/`Carbon::now('America/Panama')` en Servicios/Auth; en el frontend `utils/dates.ts` fija `timeZone: 'America/Panama'` y el locale `es-PA`.
- **Moneda y formato**: `formatMoney` fija `en-US` con 2 decimales (sin símbolo); el símbolo `B/.` apenas aparece (1 archivo backend). No hay noción de moneda por tenant.
- **El tenant no tiene país**: la tabla `tenants` solo tiene `name`, `slug`, `domain`, `is_active`.
- **Frontend**: ~25 archivos no-test con vocabulario fiscal (15 componentes + 7 páginas de `admin-contab`, más tipos); el formulario de proveedor pide RUC + DV.

## 2. Diseño propuesto (por sub-fases, cada una con su «adelante»)
**6a — Contexto regional por tenant (bajo riesgo, aditivo).** Añadir al tenant (o a una tabla de ajustes de empresa) `country` (ISO-3166), `currency` (ISO-4217), `timezone` (IANA) y `locale`. Sustituir las constantes `America/Panama` del backend y de `utils/dates.ts`
por esos valores (default = los actuales, cero cambio de comportamiento). `formatMoney` sigue sin símbolo; la moneda se muestra donde hoy ya se muestra.

**6b — Registro de proveedores fiscales por país.** Convertir `PacProviderFactory` en un registro `país → [adaptadores disponibles]` (Panamá: `msl`, `digifact`), con el catálogo visible en Configuración Fiscal. Arreglar la inconsistencia `digifact` vs fábrica.
Un país sin paquete fiscal opera con **«sin facturación electrónica»** (documento interno no fiscal) en vez de fallar. Los hosts permitidos (`fiscal-pdf.php`) pasan a ser parte del paquete del país.

**6c — Validación de identificación por país.** Extraer RUC+DV (Panamá) a una interfaz `TaxIdValidator` por país; el formulario de proveedor/cliente muestra los campos que declara el país (etiqueta «RUC», dígito verificador solo si aplica). El PAC sigue siendo quien valida contra la autoridad.

**6d — Impuesto genérico (opcional, el más grande).** Hoy el vocabulario es «ITBMS» en 62 archivos y en tablas/columnas (`itbms_rates`, `itbms_exento`, `itbms_rate_id`, `ajuste_itbms_delta`…). Dos opciones: (i) **conservar ITBMS como el nombre del impuesto de Panamá** y que el núcleo hable de «impuesto» solo en
etiquetas visibles (rápido, sin tocar esquema); (ii) **renombrar esquema y código a `tax_*`** (limpio, pero migración destructiva sobre tablas de facturación y ~100 archivos). Ver §5-3.

## 3. Fuera de alcance
- Escribir adaptadores de otros países (la Fase 6 solo deja el enchufe y deja a Panamá funcionando igual).
- Reglas de retención, libro de facturas o declaraciones de cada país.
- Aplanar migraciones históricas (fase aparte).

## 4. Criterios de aceptación (por sub-fase)
1. **6a**: con país/moneda/zona horaria por defecto (PA / USD / America/Panama) la suite sigue verde sin tocar tests; cambiar la zona de un tenant cambia las fechas del reporte de Servicios y de la pantalla, sin deploy.
2. **6b**: un tenant con un país sin paquete fiscal puede facturar internamente sin error; Panamá certifica igual que hoy con `msl`; `digifact` queda seleccionable o se retira explícitamente.
3. **6c**: un proveedor/cliente de un país sin DV no pide DV; Panamá valida igual.
4. **6d**: según la opción elegida; sin regresión en facturación, notas de crédito, libro de facturas y PDF.
5. PHPStan nivel 8, `tsc` y suites de ambos repos verdes; Senior Review por sub-fase.

## 5. Decisiones que necesita Luis
1. **Países objetivo**: ¿hay un segundo país concreto (cuál) o se deja solo el enchufe y Panamá?
2. **«Sin facturación electrónica»**: ¿un tenant sin paquete fiscal debe poder operar con documentos internos (recomendado) o solo se admiten países con paquete?
3. **Vocabulario del impuesto** (6d): conservar ITBMS como nombre del impuesto de Panamá (i) o renombrar todo a `tax_*` (ii, destructivo).
4. **Zona horaria/moneda por tenant** (6a): ¿se configuran por empresa (recomendado) o es una sola por instalación?
5. **`digifact`**: ¿se cablea en la fábrica (se mantienen los dos PAC de Panamá) o se elimina el adaptador por no estar en uso?
6. **Entrega**: ¿una sub-fase por vez, 2 PR por repo (recomendado)?

## 6. Decisiones de Luis (2026-10-26)
1. **Países**: Panamá (ya) + **Colombia** + **Estados Unidos**. La Fase 6 prepara el enchufe pensando en ellos:
   - **Colombia**: NIT + dígito de verificación (módulo 11), impuesto IVA (19 % / 5 % / 0 %) y retenciones, moneda COP, zona `America/Bogota`; la factura electrónica va por la DIAN a través de un proveedor tecnológico.
   - **Estados Unidos**: sin factura electrónica fiscal nacional (cae en «documento interno», decisión 2), EIN/Tax ID sin dígito, impuesto = *sales tax* por estado (tasa configurable como dato), moneda USD, zona por empresa.
   - **Punto a confirmar con Luis antes de la 6b**: el adaptador real de la DIAN (elegir proveedor tecnológico, certificados, habilitación) NO entra en la Fase 6 salvo que él lo pida; la fase deja el registro por país y el paquete de configuración de CO y US (validador de identificación, tasas, zona horaria, moneda).
2. **Sin facturación electrónica**: un tenant sin paquete fiscal opera con documentos internos no fiscales (sin error ni bloqueo).
3. **Vocabulario**: se conserva «ITBMS» como el nombre del impuesto de Panamá (opción i). Las tasas siguen en `itbms_rates` como tabla de tasas de impuesto; el núcleo dice «impuesto» solo en etiquetas visibles. Colombia (IVA) y EE. UU. (sales tax) reutilizan esa tabla con su propia etiqueta.
4. **Configuración por empresa**: país, moneda, zona horaria y locale por tenant (default PA / USD / America/Panama). **`digifact` se cablea** en la fábrica junto a `msl`, arreglando la inconsistencia actual.
5. **Entrega**: una sub-fase por vez, 2 PR por repo; orden 6a (contexto regional) → 6b (registro de proveedores y «sin FE») → 6c (validador de identificación por país: PA RUC+DV, CO NIT+DV, US EIN) → 6d solo etiquetas.

## 7. Resultado de la 6a — contexto regional por tenant (2026-10-26)
- **Backend**: migración landlord `2026_10_26_300000` añade `tenants.country/currency/timezone/locale` (default PA / USD / America/Panama / es-PA: cero cambio para los tenants existentes); catálogo `config/regions.php` (PA, CO, US);
  `TenantRegion` (lectura del contexto del tenant activo) y `RegionOptions`; `SwitchTenantRegionTask` alinea la zona de PHP (`date_default_timezone_set` + `config('app.timezone')`) y la de la sesión de Postgres al activar el tenant y la restaura al soltarlo.
  Comandos: `tenant:create --country/--currency/--timezone/--locale` y `tenant:set-region`. Las 7 constantes `BUSINESS_TIMEZONE` y los literales `'America/Panama'` de Servicios/Auth pasaron a `TenantRegion::timezone()`.
  `/auth/me` y el login devuelven `region`. **El perfil de empresa** (`/admin-contab/company-profile`) ahora lee y escribe país/moneda/zona/locale en el tenant (zona: cualquier IANA válida; antes solo America/Panama) y expone el catálogo `paises`.
- **Frontend**: `lib/region` (contexto por omisión de Panamá) alimentado por el store desde `user.region`; ~40 formateadores de moneda duplicados (`es-PA`/`USD`), las funciones de `utils/dates` y 4 formateadores de fecha dejaron de fijar Panamá;
  la sección «Regional» de Datos de la Empresa permite elegir país (propone moneda/zona/locale) y afinarlos.
- **Tests**: backend 5064 + 11 nuevos (`TenantRegionTest`, perfil de empresa) con la restauración del tenant en `FeatureTestCase::tearDown` (la conexión `landlord` no entra en la transacción de RefreshDatabase); frontend 235 archivos / 2950 tests; PHPStan 0; `tsc` y build limpios.
- **Cuidado**: las columnas de fecha del sistema guardan **hora local** (`timestamp without time zone`): cambiar la zona de un tenant CON datos reinterpreta lo ya guardado. Fijarla al crear el tenant (`tenant:create --country`); `tenant:set-region` avisa cuando cambia la zona.
- **No cambia**: `formatMoney` sigue sin símbolo (estándar `en-US` pedido por el cliente); ITBMS, RUC, PAC y la facturación siguen igual (6b/6c).
- Informe del Senior Review: `docs/reviews/fase6a-contexto-regional-2026-10-26.md`.

## 8. Resultado de la 6b — registro de proveedores fiscales por país (2026-10-26)
- **Registro** (`config/fiscal.php` + `FiscalProviderRegistry`): proveedores con su etiqueta y los países en que sirven — `msl` y `digifact` (Panamá) y `none` (todos). `default_by_country`: PA → `msl`; cualquier otro país → `none`. Agregar el paquete de un país = su adaptador
  + una fila + su construcción en `PacProviderFactory`.
- **`digifact` cableado** en `PacProviderFactory` (ambiente de pruebas / producción con el mismo guard de `APP_ENV` que `msl`; `tieneCredencialesReales` exige RUC, usuario y clave). **Aviso**: el adaptador se escribió contra la documentación pública y se probó con respuestas simuladas;
  `normalizeEstado()` es lo menos confirmado. Validarlo con credenciales reales del PAC antes de producción. `msl` sigue siendo el único usado en producción.
- **«Sin facturación electrónica»**: `pac_provider = none` (o el ambiente `apagado` de siempre) → `AdminContFiscalSettings::sinFacturacionElectronica()`. La empresa genera documentos internos y nada viaja a un PAC: no certifica (`no_aplica`), no valida el RUC contra la DGI, la vista previa dice «sin envío» y
  los reemplazos de notas de crédito no esperan al PAC. La fábrica lanza `SinFacturacionElectronicaException` si algún camino la pide para `none`. Se reemplazaron las 7 comprobaciones sueltas de `pac_ambiente = 'apagado'` por ese helper.
- **Coherencia con el país**: `tenant:create` y `tenant:set-region` dejan el proveedor coherente (`AlignFiscalProviderWithCountry`): si el guardado no sirve en el país nuevo (p. ej. `digifact` en Colombia) pasa a `none`; no pisa uno que sí sirve. `admin-contab:set-pac-provider` valida contra el registro y el país del tenant (acepta `none`).
- **API/UI**: la configuración fiscal devuelve `facturacion_electronica_activa`, `pac_provider_label`, `pais` y `pac_providers_disponibles`; la pantalla muestra el nombre del proveedor y, sin proveedor, explica que los documentos son internos y oculta ambiente, conexión y tipos de documento. El proveedor sigue sin editarse desde la UI (decisión de Cybersecurity de SCRUM-943).
- **Tests**: 12 nuevos (registro, fábrica con `digifact`/`none`, certificación sin proveedor, comandos, API) + 2 de frontend. **Decisión de Luis: el default de `fiscal_settings.pac_provider` pasa a `none`** (migración `2026_10_26_400000`: cambia el default de la columna y pasa a `none` las filas que seguían en `digifact`, que nunca estuvo operativo; los tenants con `msl` no se tocan): un tenant nuevo no transmite nada hasta elegir proveedor. Por eso la base de pruebas arranca en `none`
  y los tests de FE eligen su proveedor explícitamente (los 5 que usaban un proveedor «no wireado» usan ahora `proveedor-inexistente`).
- Informe del Senior Review: `docs/reviews/fase6b-registro-fiscal-2026-10-26.md`.

## 9. Resultado de la 6c — validador de identificación fiscal por país (2026-10-26)
- **Backend** (`App\Shared\Fiscal\TaxId`): interfaz `TaxIdCountryRules` (nombre, si lleva DV, validación local) con `PanamaTaxIdRules` (RUC + DV de 1-2 dígitos; formato libre, **cero cambio** respecto a antes), `ColombiaTaxIdRules` (NIT de 6-10 dígitos y DV de 1 dígito por **módulo 11 de la DIAN**:
  pesos 3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71; el DV es opcional al capturar pero, si viene, debe corresponder), `UnitedStatesTaxIdRules` (EIN `NN-NNNNNNN` o SSN/ITIN, 9 dígitos, sin DV) y `GenericTaxIdRules` (país sin reglas: alfanumérico de 3-100). `TaxIdRulesRegistry` las resuelve por país (`config/fiscal.php` → `tax_id_rules`).
- **Dónde se aplica** (`ValidTaxId`, regla de validación con el DV como dato asociado): alta/edición de clientes y subclientes (`ReceptorTipo::reglas()`), corrección del receptor de una factura y proveedores **locales** (categoría `locales`); un proveedor del exterior trae su propio identificador y no se somete a las reglas del país de la empresa.
- **API**: `/auth/me` y el login devuelven `fiscal_id` (`label`, `uses_dv`, `dv_max_length`) del país de la empresa.
- **Frontend**: `useFiscalId` + `fiscalIdText`; los formularios de cliente (alta y edición de datos fiscales) y de proveedor muestran «NIT» o «EIN / Tax ID» en lugar de «RUC» y ocultan el DV donde no aplica; en Panamá los textos no cambian. La guarda del receptor al facturar sigue siendo la de la DGI (solo corre con el PAC activo).
- **Tests**: backend 5093 (+16: reglas por país con NIT reales de la DIAN —Universidad Nacional 899999063-3 y DIAN 800197268-4—, validación por país del tenant, clientes, proveedores local/exterior y payload); frontend 236 archivos / 2959 tests (+ hook y modal de edición por país).
- Informe del Senior Review: `docs/reviews/fase6c-validador-identificacion-2026-10-26.md`.
