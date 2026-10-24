# Review: separar la corrida semanal de ICG de dev y test
**Date**: 2026-09-28
**Reviewer**: Senior Reviewer (lente narrada en el mismo hilo, no subagente)
**Commit revisado**: `0456b76` (local, sin push), luego ajustado a dev 05:00 y re-commiteado
**Decision**: ✅ Approved. Sugerencia 1 resuelta: Luis eligió dev a las **05:00** (2026-09-28)

## Intención
No hay spec formal. La intención es la aprobada por Luis en el chat el 2026-09-28: dev y test corrían
`legacy:sync-incremental-all` a la misma hora (domingo 01:00 Panamá) y sus cadenas leían ICG en
simultáneo. Test se queda en 01:00, dev pasa a 03:30 (propuesta inicial; ver decisión final más abajo), el health-check no cambia.

## Verificación ejecutada
- Backend completo: `infra/test.sh` → **3903 tests, 16927 assertions, OK, 1 skipped** (el skipped no se investigó).
- PHPStan proyecto completo (`--memory-limit=1G`) → **No errors**.
- Frontend completo: `npx vitest run` → **182 archivos, 2053 tests, todos pasan**. Sin relación con el cambio, corrido por el gate.
- Playwright: no aplica (sin UI).
- `runsInEnvironment()` por ambiente: dev = `30 3 * * 0`, qa = `0 1 * * 0`, production = `0 21 * * *`, todos America/Panama.
- Health-check: ventana dev/test = [domingo 01:00, lunes 05:00). Ambos slots caen adentro, con ~24 h de margen hasta el chequeo.

## Findings

### 🔴 Blockers
Ninguno.

### 🟡 Suggestions (no bloqueantes)
1. **`routes/console.php:83` — el slot de dev (03:30) cae en la franja de los jobs diarios.**
   `audit-logs:archive` corre a las 03:30 (mismo minuto; despacha un job que archiva a S3),
   `commissions:settle-internal` a las 03:45 y `security:analyze` a las 03:00, todos en dev. La cadena
   dura ~75-80 min, así que comparte host con los tres. Dev es una t3.small de 2 GB con historial de
   OOM cuando corrieron varias entidades a la vez (SCRUM-918). Riesgo no medido: no se verificó el
   peso real de esos jobs ni la memoria de dev en esa franja. Quien recomendó 03:30 no revisó el resto
   del schedule antes de proponerlo.
   Suggestion: **04:00** (después del último job diario de las 03:45). Margen tras el fin de test
   (~02:15) = ~105 min; la cadena de dev termina hacia ~05:20 del domingo, y el health-check del lunes
   05:00 sigue con ~24 h de holgura. Alternativa: dejar 03:30 y observar la primera corrida.
2. **Sin test del schedule.** Nada impide que una edición futura vuelva a poner dev y qa a la misma
   hora. Suggestion: un test de feature que cargue `Schedule` y afirme que las entradas de
   `legacy:sync-incremental-all` para dev y qa tienen expresiones distintas.
3. **Comentario desactualizado (ya corregido, sin commitear)**: `routes/console.php:4` decía
   "test/dev 1x/semana (domingo 01:00)"; ahora dice test 01:00 / dev 03:30.

### 🟢 Well done
- Dos entradas con `environments()` disjuntos; los mutex de `withoutOverlapping()` son independientes.
- Los comentarios explican el porqué (lectura completa de ICG en cada corrida, margen para que las cadenas crezcan).
- El health-check no requirió cambios de lógica; solo se actualizó su comentario.

## Next steps
**Decisión de Luis: dev a las 05:00.** La cadena de dev (~80 min) termina hacia las ~06:20 del domingo, después de todos los jobs diarios (último: 03:45) y con ~24 h hasta el health-check del lunes 05:00. Test sigue en 01:00 (termina ~02:15). Aprobado para push a `dev` cuando Luis lo autorice. Los tests/PHPStan de arriba se corrieron con el slot de 03:30; el cambio a 05:00 solo modifica el valor de un `weeklyOn()` y comentarios, no se re-corrió la suite completa.
Al desplegar, no hacerlo domingo 01:00 ni en el slot que quede para dev (una corrida ya se perdió en test por eso el 2026-09-27).
