# Review: SCRUM-939 — Excel de Estado de Cuenta, Posición de Caja y Facturas con montos numéricos

**Date**: 2026-09-23
**Reviewer**: Senior Reviewer
**Decision**: ✅ Approved

## Summary

Sigue exactamente el patrón ya aprobado en SCRUM-935 (`ExcelMoney`, `strictNullComparison`) para
los 3 reportes que quedaron fuera de ese batch. Cambio mecánico y acotado a las 3 clases
`*ExportService` de `app/Modules/AdminCont/Services/` — sin tocar controllers, rutas ni el PDF de
`InvoiceExportService` (que sigue con string formateado a propósito, porque renderiza texto, no
celdas). PHPStan Level 8 limpio (full-project), suite completa backend (3683 tests) y frontend
(1981 tests) en verde.

## Findings

### 🔴 Blockers (must fix)
Ninguno.

### 🟡 Suggestions (non-blocking)
1. **`CashPositionExportService.php:82` (`buildSheet`)** — el parseo de `$moneyRange` (`'D'` vs
   `'D:F'`) con `str_contains`/`explode` es un poco más código del necesario para 2 usos internos.
   No bloquea — es privado, con un solo caller por vista, y ya tiene PHPDoc explicando el formato
   esperado.

### 🟢 Well done
- **Cobertura del AC "los negativos (notas de crédito) quedan numéricos"** — el primer pase de
  tests no cubría explícitamente un valor negativo en ninguno de los 3 reportes (todos los casos
  nuevos usaban montos positivos). Encontrado en esta revisión y corregido en la misma sesión:
  `AdminContCashPositionControllerTest::test_939_excel_proyectado_escribe_salida_de_nota_de_credito_como_celda_numerica_negativa`
  reusa el fixture ya existente de nota de crédito pendiente (única salida real que se escribe en
  negativo, `-1 * monto`, en estos 3 reportes) y verifica celda numérica Y valor negativo, no solo
  el formato.
- **`InvoiceExportService`** — separó correctamente `labelParts()` (compartido) de `rowValues()`
  (PDF, string) y el mapeo de `toExcel()` (numérico), en vez de mutar el array devuelto por
  `rowValues()` por índice — evita el riesgo de que PHPStan (o un futuro cambio del orden de
  columnas) deje pasar un mismatch de tipo en silencio.
- Cada archivo de test usa el trait `ReadsXlsxResponse` ya existente (mismo mecanismo de SCRUM-935)
  en vez de reinventar la lectura del `.xlsx` — consistente con el resto del módulo.

## Next steps

Ready for QA. Sin cambios de esquema/arquitectura — no requiere paso adicional por el Arquitecto.
Pendiente: push a `dev` con OK explícito de Luis (gate de `git push`, no se saltea).
