# Senior Review — SCRUM-933 (Registrar Abonos — búsqueda por Proyecto y # de Cotización)

Senior Reviewer, 2026-09-20. Diseño: `ADR-SCRUM933-registrar-abonos-correcciones.md`. Implementación:
Backend Dev (B1-B2) + Frontend Dev (F1).

## Alcance revisado

Backend: `AbonoService::invoicesForSubClient()` (nuevo, mismo patrón que
`CreditNoteService::invoicesForClient()`), ruta nueva `GET .../abonos/cascada/facturas-por-subcliente`
— las 3 rutas/métodos de cascada existentes quedan intactos. Frontend: `RegistrarAbonoModal.tsx` —
Proyecto pasa de `<select>` a autocomplete de texto, # de Cotización nuevo (mismo patrón), cruce
bidireccional real, Factura acotada con el mismo saneamiento ya usado en SCRUM-931/932.

## Sin hallazgos — el patrón de saneamiento se aplicó desde el primer intento

A diferencia de SCRUM-931 (donde encontré el bug) y SCRUM-932 (donde se corrigió solo, en el mismo
intento), acá Frontend Dev aplicó el `useEffect` de poda de `invoiceId` **desde el primer intento**,
sin haber hecho falta señalarlo. Tres implementaciones seguidas del mismo módulo, tres resultados
distintos del mismo tipo de bug — buena señal de que el patrón quedó internalizado para el resto de
la sesión.

Verifiqué un detalle propio de esta implementación (autocomplete en vez de `<select>`, único de este
ticket): la hidratación de un abono existente usa `abono.proyecto` (campo ya existente en el tipo
`Abono`, confirmado — no es una invención) para el texto del autocomplete; la Cotización nunca se
persiste (correcto según el ADR, es solo mecanismo de búsqueda) así que un abono existente nunca la
trae preseleccionada — comportamiento esperado, no un gap.

Hallazgo NO bloqueante, correctamente diagnosticado por Frontend Dev como preexistente y fuera de
alcance: la sección `abonos.form.*` completa (no solo las 2 claves nuevas) falta en
`en/adminContab.json` — confirmé con `git show HEAD:...` que ya faltaba antes de este ticket, no es
una regresión de SCRUM-933. Queda como deuda conocida, no se abre ticket de seguimiento por esto
(alcance menor, un solo formulario en un solo idioma no soportado activamente).

## Gate completo (regla dura del proyecto)

- **Backend:** PHPStan Level 8 limpio. `infra/test.sh --filter="Abono"` → 13/13 verdes. Suite
  completa (sin nada corriendo en paralelo) → 3602/3602 verdes (limpio de punta a punta, sin el
  flaky de concurrencia esta vez). Test nuevo con fixtures reales, incluye caso negativo explícito
  (facturas de otro sub-cliente no aparecen).
- **Frontend:** `tsc --noEmit` limpio. `npx vitest run` completo — 1921/1921 verdes (4 tests nuevos:
  opciones acotadas al sub-cliente, cruce en ambas direcciones, poda de factura oculta).
- Pendiente: Playwright — corre en el paso de Pre-QA (sin mockup adjunto en el ticket, Visual
  Review no aplica).

## Veredicto

**Aprobado.** Diseño respetado, incluida la divergencia deliberada de UI (autocomplete en vez de
`<select>`) ya aprobada por Luis antes de implementar. Pasa a Pre-QA.
