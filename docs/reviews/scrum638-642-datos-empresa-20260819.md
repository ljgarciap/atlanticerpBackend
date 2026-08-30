# Senior Review — Admin&Cont, Datos de la Empresa (SCRUM-638→642, REQ-561→565)

**Fecha:** 2026-08-19
**Entorno:** working tree local (atlanticerp-backend + atlanticerp-frontend) al momento de la revisión.
**Resultado: 1 hallazgo MAYOR (corregido y re-verificado, ver `docs/pre-qa/scrum638-642-datos-empresa-20260819.md`).**

Segundo batch del módulo `App\Modules\AdminCont` (el primero, Configuración Fiscal SCRUM-632→637,
ya en dev/QA) — reusa el mismo middleware `EnsureIsMarkApprover` y el mismo patrón de settings
singleton.

## Gate real (suite completa, no scoped)

- Backend: PHPStan Level 8 → `[OK] No errors`. `infra/test.sh` completo → 1 falla inicial en
  `HomeControllerTest.php` (unique violation de folio, módulo Servicios) confirmada no relacionada
  a este batch — re-corrida en aislamiento → limpia. Resto de la suite OK.
- Frontend: `npx vitest run` → **114 archivos, 1295/1295 OK** (antes del fix de key; 1296/1296 tras
  agregar el test de regresión).

## MAYOR — colisión de `key` de React en la tabla de Ubicaciones

`GET /api/admin-contab/locations` combina dos tablas con PK independientes: las 7-8 filas de
`App\Modules\Bodega\Models\Warehouse` (`source:'bodega'`) y las de `admin_contab_locations`
(`source:'admin_contab'`, autoincremental desde 1 también) — los `id` numéricos de ambas fuentes
NO son mutuamente exclusivos (documentado explícito en el docblock de
`AdminContLocationController`). `CompanyProfilePage.tsx:500` usaba `key={loc.id}` sin distinguir la
fuente — la primera ubicación administrativa que se cree (id=1) colisiona con "Bodega Central"
(Warehouse id=1), riesgo real de reconciliación incorrecta de React entre ambas filas.

El toggle de activar/desactivar en sí NO tenía riesgo (`onToggle(loc, activa)` usa el objeto `loc`
completo de su propia fila, y el backend solo permite `PUT` sobre `admin_contab_locations` reales)
— el hallazgo es puramente de key/render, no de mutación cruzada.

**Fix aplicado** (mismo día, por el Arquitecto directamente — cambio de una línea, contexto ya
verificado): `key={`${loc.source}-${loc.id}`}`. Se agregó test de regresión con ids colisionantes
reales (`source:'bodega'` id=1 + `source:'admin_contab'` id=1) que confirma ambas filas renderizan
distintas y el toggle afecta solo la fila correcta.

## Arquitectura — verificado en código, sin más hallazgos

- `EnsureIsMarkApprover` (`mark_only`) aplicado a las 8 rutas nuevas.
- `razon_social`/`nombre_comercial` leídos en vivo de `AdminContFiscalSettings::current()`, nunca
  duplicados, nunca aceptados en `UpdateCompanyProfileRequest`.
- `moneda`/`anio_fiscal` ausentes de las reglas del request — nunca editables. `zona_horaria`
  restringida a `in:America/Panama` (422 si viene otro valor).
- `StoreLocationRequest` restringe `tipo` a `in:oficina,otra` — nunca se puede crear una fila
  Bodega/Showroom desde acá.
- Logo: disk `s3-public` explícito (no `visibility=>'public'`, gotcha de ACL ya documentado),
  `CircuitBreaker` envolviendo el upload, borra el logo anterior tras subir uno nuevo.
- Clasificación de tipo para las filas de `Warehouse` (`str_starts_with($w->name, 'Showroom')` →
  Showroom, si no → Bodega) correcta contra los 7-8 nombres reales — el diseño de lectura en vivo
  capturó automáticamente la 8ª bodega ("Reserva Servicios", agregada en una migración posterior a
  cuando se escribió el ticket) sin necesidad de ajustar el código, validando la decisión de no
  hardcodear el catálogo.

## Veredicto

Listo para Visual Review (mockup adjunto en SCRUM-638: `4L__Admin_Contabilidad_Info_Empresa.html`)
y Pre-QA. Ver `docs/pre-qa/scrum638-642-datos-empresa-20260819.md` para el resultado completo de
ese gate y el estado final de despliegue.
