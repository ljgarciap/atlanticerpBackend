# Senior Review — Fase 6b: registro de proveedores fiscales por país y «sin facturación electrónica»

**Fecha**: 2026-10-26 · **Spec**: `docs/specs/fase6-fiscal-por-pais.md` (§6, §8) · **Veredicto**: APROBADO, 0 bloqueantes.

## Qué se revisó
- `config/fiscal.php` + `FiscalProviderRegistry`: solo responde preguntas (qué sirve en un país, valor por omisión, validez); no construye ni toca la base. `PacProviderFactory` sigue siendo el único lugar que arma adaptadores y mantiene el guard de `APP_ENV` para producción en ambos PAC.
- `AdminContFiscalSettings::sinFacturacionElectronica()` reemplaza las 7 comprobaciones sueltas de `pac_ambiente = 'apagado'` (certificación, validación del receptor, RUC contra la DGI, vista previa, notas de crédito, revalidación masiva): el comportamiento del ambiente apagado no cambia y el proveedor `none` hereda exactamente el mismo.
- `none` no construye adaptador (`SinFacturacionElectronicaException`): fallo explícito en vez de enviar a un PAC equivocado.
- `AlignFiscalProviderWithCountry` en `tenant:create` y `tenant:set-region`: solo cambia un proveedor que no sirve en el país; no pisa uno válido. `admin-contab:set-pac-provider` valida contra el registro y el país.
- El recertificado manual (`FiscalCertificationService`) conserva `pac_provider === 'msl'`: es lógica específica de las transmisiones de MSL; `digifact` no entra ahí a propósito.
- Frontend: tipos, panel de Configuración Fiscal (nombre del proveedor; sin proveedor → aviso y sin ambiente/conexión/tipos de documento).

## Verificación
- Backend: suite completa (ver cifra en la spec §8) sin fallos; PHPStan nivel 8 limpio. Los 30 fallos de la primera corrida tenían una sola causa: tests que daban por hecho que `digifact` no estaba cableado (default de la columna); se resolvió fijando la base de pruebas en `none` y los tests de FE usan su proveedor explícito.
- Frontend: 235 archivos / 2952 tests, `tsc` y build limpios.

## Hallazgos (sin bloqueantes)
1. **Default de la columna**: `fiscal_settings.pac_provider` sigue con default `digifact` en la migración histórica (los tenants nuevos de Panamá salen con `digifact` hasta que alguien ejecute `admin-contab:set-pac-provider msl`). Antes la fábrica lo rechazaba (fallo seguro); ahora lo construye. Conviene una migración que cambie el default a `none` o aplicar el valor del registro (`msl`) al crear el tenant — no se hizo para no cambiar el comportamiento de los tenants nuevos de Panamá sin decisión de negocio. **Pendiente de decisión de Luis.**
2. `digifact` solo se probó con respuestas simuladas; no usarlo en producción sin validarlo con credenciales reales.
3. Colombia y EE. UU. quedan en `none` hasta tener su adaptador (la DIAN es una fase aparte, por decisión de Luis).
