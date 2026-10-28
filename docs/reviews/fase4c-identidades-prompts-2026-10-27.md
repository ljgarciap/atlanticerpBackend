# Senior Review — Fase 4c (identidades y prompts genéricos)

**Veredicto**: aprobada, 0 bloqueantes. Spec: `docs/specs/fase4c-identidades-y-prompts.md`.

## Revisado
- `BusinessProfile` + `BaseAiJob::run()`: el marcador `{negocio}` se sustituye DESPUÉS de `makeCurrent()`, así que `company_profile` se lee del tenant correcto. `SecurityAnalysisJob` ya tenía el tenant activo. Sin marcador el prompt no cambia.
- Seguridad: el texto entra al prompt de SISTEMA. Lo editan solo Gerencia y superadmin (gate exclusivo de «Datos de la Empresa») y se acota a 500 caracteres. Riesgo aceptado, de bajo impacto.
- Se corrigió en la revisión: `description()` usaba `findOrFail(1)`; ahora tolera la fila ausente («una empresa») para que un job de IA no falle por esto.
- PDF: sin razón social, ciudad ni dominio. La moneda `$` fija del PDF no se tocó (fuera de alcance).
- Renombre de identidades: reemplazo exacto sobre ~370 archivos; migraciones históricas y `docs/` intactas. Sin colisiones previas con `vendedor@`, `tecnico@`, `nivelN@`.
- Contrato: `ARCHIVOS_PENDIENTES_4C` vacío; los correos viejos quedan prohibidos.

## Verificación
PHPStan 0 errores. Backend 5153 tests: 12 fallos de nombres visibles/búsquedas, corregidos y reverificados. Frontend `tsc` limpio, 242 archivos / 2978 tests.

## Sugerencias (no bloqueantes)
1. `VentasDisenoSalesGoalsSeeder` conserva apodos de personas reales en comentarios; los contratos no cubren nombres de personas.
2. Los e2e renombrados no se ejecutaron (no corren en CI).
3. Los comentarios de `docs/` y migraciones históricas conservan los nombres viejos a propósito.
