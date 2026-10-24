# Review: SCRUM-944/945 — 2da pasada: comisión de ventas + Gerencia a saldo neto

**Date**: 2026-09-23
**Reviewer**: Senior Reviewer
**Decision**: ✅ Approved

## Summary

Segundo pase sobre el mismo batch (`docs/reviews/scrum944-945-auditoria-completa-20260923.md`
cubrió el primero). Luis pidió explícitamente "corrige lo que encontraste de comisiones y
gerencia" — dos decisiones de negocio que solo él podía tomar, ambas resueltas con
`AskUserQuestion` antes de tocar código:

1. **Comisiones** — migrar `SalesTotalsService::percentFor()` (tabla de comisión de ventas
   hardcodeada) pese a la decisión previa del 2026-08-25 de mantenerla así. Confirmado
   explícitamente por Luis en el chat.
2. **Gerencia** — aplicar la sugerencia no-bloqueante del review anterior (reusar
   `InvoiceService::cobrablesConVencimientoSigned()`), que trajo consigo una segunda decisión real
   no anticipada: saldo bruto vs. neto. Confirmado explícitamente vía `AskUserQuestion` → saldo
   neto.

## Findings

### 🔴 Blockers (must fix)
Ninguno.

### 🟡 Suggestions (non-blocking)
Ninguna nueva.

### 🟢 Well done
- **Comportamiento preservado exactamente en la migración de comisiones** — los 6 tramos
  sembrados replican el corte estrictamente-menor (`$total < 20000`) del `match(true)` original
  usando `monto_maximo` en ".99" en vez de en el corte redondo, con el modelo de rango inclusivo
  ya usado por `commission_tiers`. Test cubre los 12 puntos de corte (justo abajo y justo en cada
  límite), no solo un par de valores de ejemplo.
- **`$total <= 0` sin guarda especial, documentado a propósito** — el código original devolvía
  1.5% para `$0` (cae en el primer tramo por `<`); se preservó el mismo resultado dejando que
  `monto_minimo=0` lo capture naturalmente, en vez de agregar un `if ($total <= 0) return 0` que
  hubiera cambiado el comportamiento en silencio.
- **El cambio de Gerencia se detuvo a preguntar** en vez de decidir solo — sumar `saldo` en lugar
  de `monto` cambia el número real que ve Gerencia en el dashboard ejecutivo (baja si hay facturas
  con abonos parciales); encontrarlo mientras se aplicaba "solo un refactor" y pausar para
  confirmarlo con Luis en vez de aplicarlo en silencio es el comportamiento correcto para un
  cambio de cifra financiera.
- **ADR original actualizado, no reescrito** — `ADR-SCRUM575-579-batch14-comisiones-internas.md`
  ahora tiene una nota al inicio marcando su §1 como histórica/superada, en vez de borrar o
  contradecir en silencio el registro de la decisión de 2026-08-25.
- **Tests de Gerencia nuevos verifican el delta exacto**, no solo "sigue devolviendo 200" —
  `test_cxc_total_usa_saldo_neto_no_monto_bruto` compara antes/después con una factura de abono
  parcial conocido (1000 monto, 400 aplicado → el total debe subir 600, no 1000).
- **Gotcha de `loginAs()` reseteando el tenant actual, ya documentado en este mismo archivo,
  reapareció y se corrigió** en el primer intento fallido (test que creaba el fixture DESPUÉS de
  loginear) — confirma que vale la pena tenerlo anotado.

## Gate

- `vendor/bin/phpstan analyse --memory-limit=1G` (full-project): sin errores.
- `./infra/test.sh` completo: 3698 tests, 15817 assertions, en verde.
- `npx tsc --noEmit`: sin errores.
- `npx vitest run` completo: 179 archivos, 1994 tests, en verde.

## Next steps

Ready for QA. Pendiente: push a `dev` (ambos repos) y deploy, con OK explícito de Luis.
