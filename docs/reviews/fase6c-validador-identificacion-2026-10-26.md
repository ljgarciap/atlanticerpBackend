# Senior Review — Fase 6c: validador de identificación fiscal por país

**Fecha**: 2026-10-26 · **Spec**: `docs/specs/fase6-fiscal-por-pais.md` (§6, §9) · **Veredicto**: APROBADO, 0 bloqueantes.

## Qué se revisó
- Reglas por país detrás de una interfaz (`TaxIdCountryRules`) y un registro por configuración: agregar un país es una clase y una fila. Panamá conserva exactamente el comportamiento anterior (DV de 1-2 dígitos, identificación libre); el RUC real lo sigue validando la DGI vía PAC.
- NIT colombiano: algoritmo del módulo 11 verificado contra dos NIT publicados (899999063-3 y 800197268-4); el DV es opcional al capturar y se valida si viene (misma política que Panamá: lo exige quien factura).
- `ValidTaxId` (regla con datos asociados): no corre con el campo vacío (Consumidor Final / identificación opcional) y admite una condición de aplicación. Se usa en clientes, en la corrección del receptor y en proveedores **locales**: un proveedor del exterior no se somete a las reglas del país de la empresa (cubierto con un proveedor de China).
- Frontend: etiqueta y DV por país con fallback a Panamá; el DV se corta al largo del país; si el país no usa DV, no se muestra ni se envía.

## Verificación
- Backend: suite completa 5093 tests sin fallos; PHPStan nivel 8 limpio. Frontend: 236 archivos / 2959 tests, `tsc` y build limpios.
- Incidente de proceso resuelto en el camino: un script de edición reemplazó por error el contenido de dos archivos de test; se restauraron desde git y se reaplicaron los cambios (el árbol final solo contiene los archivos pretendidos).

## Hallazgos (sin bloqueantes)
1. **Datos existentes**: las reglas solo corren al guardar; un cliente o proveedor local ya guardado con una identificación que no cumpla las reglas de su país no se toca, pero fallará al editarlo. Aplicable a tenants que cambien de país con datos (ver 6a: cambiar el país de un tenant con datos es una decisión de negocio).
2. **Reglas laxas a propósito**: Panamá no restringe el formato (hay muchos) y Colombia exige solo 6-10 dígitos y el DV; no se valida contra el RUES/DIAN ni el EIN contra el IRS (eso es del PAC/otro servicio).
3. **ReceptorGuardNotice** (guarda de la DGI al facturar) mantiene su texto de Panamá: solo aparece con el PAC activo, que hoy es exclusivo de Panamá.
