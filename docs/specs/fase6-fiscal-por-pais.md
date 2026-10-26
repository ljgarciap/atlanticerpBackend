# Spec — Fase 6: fiscal por país (Panamá como el primer paquete)

**Estado**: BORRADOR — pendiente de las decisiones de Luis (§5) y de su «adelante». No se implementa nada antes.
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
