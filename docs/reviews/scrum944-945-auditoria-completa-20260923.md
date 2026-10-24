# Review: SCRUM-944/945 — Auditoría de valores hardcodeados + vista consolidada (completo)

**Date**: 2026-09-23
**Reviewer**: Senior Reviewer
**Decision**: ✅ Approved

## Summary

Cierra los 2 deliverables de la spec (`docs/specs/auditoria-valores-parametricos-superadmin.md`):
auditoría real de `app/Modules/**/Services|Controllers|Jobs` (evidencia completa en
`docs/architecture/auditoria-valores-parametricos-scrum944.md`) y vista consolidada de superadmin
(`GlobalSettingsPage`, 10 tabs — los 9 módulos ya relevados en la spec + CRM, agregado por la
auditoría misma). Este review complementa
`docs/reviews/scrum944-vista-consolidada-frontend-20260923.md` (que cubre solo el contenedor de
tabs y la extracción Page→Panel de Fiscal/Fraude/Bodega/Servicios) con lo que faltaba: los 3
hallazgos reales de la auditoría (CRM migrado, Gerencia corregido, VentasDiseno correctamente
excluido) y el panel de CRM agregado después de ese review parcial.

## Findings

### 🔴 Blockers (must fix)
Ninguno.

### 🟡 Suggestions (non-blocking)
1. **`GerenciaHomeService.php`** — el fix reusa `dias_credito_factura` pero sigue reimplementando
   la fórmula de vencimiento con una query propia, en vez de llamar a
   `InvoiceService::cobrablesConVencimientoSigned()` (que ya centraliza exactamente este cálculo,
   ver docblock de ese método). Documentado a propósito como fuera de alcance en la evidencia de
   auditoría (migrar storage, no reescribir cálculos duplicados) — pero es la reimplementación
   nº2 del mismo patrón que `docs/pre-qa/gerencia-epic-scrum325/gerencia-scrum168-20260826-v9-recheck.md`
   ya señaló una vez. Sugerido como ticket de seguimiento, no bloqueante acá.
2. **`docs/reviews/scrum944-vista-consolidada-frontend-20260923.md`** vive en `atlanticerp-backend` pese
   a revisar código de `atlanticerp-frontend` — inconsistente con dónde vive el resto de reviews de
   frontend (si los hay). No bloqueante, cosmético.

### 🟢 Well done
- **El hallazgo más significativo (`SalesTotalsService::percentFor()`, tabla de comisión de
  ventas hardcodeada) se investigó hasta el final y se encontró una decisión previa y explícita de
  Luis (`docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md` §1, 2026-08-25) de mantenerla
  hardcodeada a propósito — deuda técnica conocida, no un hallazgo nuevo.** Migrarla sin que Luis
  lo pidiera de nuevo hubiera revertido esa decisión en silencio. Se documentó como exclusión
  explícita en vez de "no encontrado" o "migrado sin preguntar" — el resultado correcto entre las
  3 opciones.
- **Fix de Gerencia con test real** — el bug (CxC vencidas con "30" hardcodeado en vez del plazo
  de crédito real) no tenía NINGÚN test previo cubriéndolo; el test nuevo usa un plazo de crédito
  DISTINTO de 30 (10 días) para que el hallazgo quede realmente cubierto, no solo "sigue pasando
  con el default".
- **Hallazgos borderline documentados, no descartados en silencio** (Bodega `subDays(7)` de
  "entregados esta semana") — la evidencia de auditoría explica por qué no califica bajo la
  definición acordada, en vez de simplemente omitirlo.
- **`crm_settings` sigue el patrón ya establecido** (`BodegaSetting`/`BodegaSettingsService`, key/
  value) en vez de inventar uno nuevo — CRUD gateado por `superadmin.all` directo (sin permiso de
  negocio intermedio), correcto dado que no hay pantalla propia de CRM para esto todavía.
- **Test de filtro `urgency=proximo`** verifica el comportamiento real (un proyecto con entrega en
  8 días, invisible bajo el límite viejo de 3, aparece con el límite configurado a 10) — no solo
  que el GET/PUT de settings funciona.

## Gate

- Backend: `vendor/bin/phpstan analyse --memory-limit=1G` (full-project) sin errores. `./infra/test.sh`
  completo: 3691 tests, 15785 assertions, en verde.
- Frontend: `npx tsc --noEmit` sin errores. `npx vitest run` completo: 178 archivos, 1990 tests,
  en verde.

## Next steps

Ready for QA. Pendiente: push a `dev` (ambos repos) y deploy, con OK explícito de Luis — no se
saltea el gate de `git push` en ningún modo.
