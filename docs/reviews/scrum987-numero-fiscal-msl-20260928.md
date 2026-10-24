# Review: SCRUM-987 — numero fiscal puramente numérico para el PAC (MSL)
**Date**: 2026-09-28
**Reviewer**: Senior Reviewer
**Decision**: ✅ Approved

## Summary
Corrige un bug real y bloqueante encontrado por Pre-QA (SCRUM-958, sesión de retest de sandbox de
MSL): el `numero` que AtlanticERP mandaba al PAC era el folio interno completo ("F-0013"/"NC-0001"), que
la DGI rechaza por no ser puramente numérico ("9026 - Falló la validación de algunos escenarios").
Confirmado empíricamente antes de escribir el fix (llamada real y aislada a MSL sandbox, un solo
campo variado: "F-0013" rechazado, "9001" certificado con CUFE real) — no es una hipótesis. El fix
es acotado (un método nuevo, dos call sites, un tercer call site de consistencia en el polling) y
viene con evidencia de producción real, no solo de test. Encontré y corregí 2 issues menores
durante esta revisión (ver abajo) antes de aprobar — quedan reflejados en el diff final, no como
deuda pendiente.

## Findings

### 🔴 Blockers (must fix)
Ninguno.

### 🟡 Suggestions (non-blocking, ya resueltas en este mismo pase)
1. **`FiscalDocumentBuilder::numeroFiscal()`** — la primera versión recortaba ceros a la izquierda
   (`ltrim(..., '0')`), una transformación extra sin evidencia: la prueba aislada solo confirmó que
   letras/guión rompen la validación de la DGI, nunca que un cero a la izquierda también lo haría.
   Corregido para conservar el padding ("F-0013" -> "0013", no "13") — cambia solo lo que se
   confirmó roto.
2. **Cobertura de test** — las aserciones originales en `FiscalDocumentBuilderNotaCreditoTest`
   calculaban el valor esperado reimplementando la misma regex que el propio método bajo prueba
   (nunca hubiera atrapado una regresión en la transformación en sí). Agregado
   `tests/Unit/AdminCont/FacturacionElectronica/FiscalDocumentBuilderNumeroFiscalTest.php`, puro,
   con expectativas hardcodeadas — esa es la que de verdad protege la lógica.

### 🟢 Well done
- El fix no se quedó en el síntoma (factura rechazada) — se rastreó hasta que el polling
  (`PollMslDocumentEstadoJob`) también hubiera quedado roto de forma silenciosa si no se corregía
  en paralelo: sin este segundo cambio, cualquier documento que MSL dejara en `pendiente` (202)
  jamás se hubiera podido consultar de nuevo, porque el job habría preguntado por el folio interno
  en vez del numero que MSL realmente indexó. Separar `numero` (clave real en `invoices`/
  `credit_notes`) de `numeroFiscal` (lo que de verdad viaja al PAC) en el job es la solución
  correcta, no un parche.
- Evidencia real adjunta en el docblock del método (CUFE real, fecha, contraste "roto"/"funciona")
  en vez de una explicación abstracta — cualquiera que lea el código en 6 meses entiende
  exactamente por qué existe sin tener que ir a buscar el ticket.
- El folio `F-XXXX`/`NC-XXXX` no se tocó en ningún otro lugar (UI, PDF, `invoices.numero`) — el
  cambio es exclusivamente en el payload saliente hacia el PAC, alcance mínimo real.

## Verificación
- `infra/test.sh --filter="FiscalDocumentBuilder|PollMslDocumentEstadoJob|FiscalCertificationService|FiscalDocumentPreview|CertifyCreditNote|CertifyInvoiceOnCreated"` — 44/44 verde (40 preexistentes + 4 nuevos).
- PHPStan Level 8 sobre los 3 archivos de `app/` tocados — sin errores.
- Los tests corren contra `tests/Unit` (puro) y `tests/Feature` (con DB/tenant) — ninguno pega a la red real (mismo criterio ya vigente en el módulo, `Http::fake()`).

## Pendiente, fuera de alcance de este fix (no bloquea aprobar)
- No se confirmó contra el sandbox real si un `numero` con ceros a la izquierda ("0013") certifica
  igual que uno sin ellos ("13") — se optó por el cambio más conservador (no tocar el padding) en
  vez de asumir. Si Pre-QA lo prueba más adelante y encuentra que SÍ hace falta recortarlos, es un
  cambio de una línea en `numeroFiscal()`.
- Mismo bug, mismo fix, en el hipotético adapter `DigifactPacAdapter` (mergeado pero sin wirear,
  ver SCRUM-941) — no se tocó porque no está en uso; si algún día se activa, revisar si tiene la
  misma restricción antes de asumir que este fix ya lo cubre (usa el mismo `FiscalDocument`
  canónico, así que en principio sí lo hereda, pero no se probó contra Digifact real).

## Next steps
Ready for push a `dev` (pendiente aprobación individual del gate de `git push`, como siempre) y
luego para que Pre-QA retome SCRUM-958 pasos 9/13-16 contra una factura certificada de verdad.
