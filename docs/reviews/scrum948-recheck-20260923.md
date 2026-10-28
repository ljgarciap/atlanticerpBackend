# Review: SCRUM-948 (recheck real — no era solo promoción dev→test)
**Date**: 2026-09-23
**Reviewer**: Senior Reviewer
**Decision**: ✅ Approved

## Summary
Hallazgo del proceso, no solo de código: el comentario de Gerencia1 ("no veo el buscador") en
Registrar Abono era correcto. Una investigación previa en esta misma sesión había concluido
erróneamente que era un tema de promoción `dev→test` no promovida. Re-investigación con evidencia
real (capturas de Luis) encontró la causa real: el 2026-09-22 se resolvió una versión más angosta
de este mismo número de ticket (agregar un botón "limpiar" a la cascada de SCRUM-933 en el modal
de creación), y un Pre-QA de ese día la dio por buena — pero el texto vigente del ticket (creado
2026-09-21, "Replicar buscadores de Cobros en Registrar Abono") pedía algo distinto: que la tabla
"Abonos registrados" tuviera los mismos buscadores/filtros que "Historial de cobros". Eso nunca se
construyó. `AbonosPage.tsx` no tenía ningún buscador ni filtro antes de este batch.

Implementación: backend agrega `metodo_pago` + rango `desde`/`hasta` al endpoint ya existente
(que soportaba `search`/`master_client_id`/`estado`); frontend replica el patrón visual e
interacción de `HistorialCobrosPanel.tsx` (no lo reusa como componente — entidad distinta) sobre
`AbonosPage.tsx`.

## Findings

### 🔴 Blockers (must fix)
Ninguno.

### 🟡 Suggestions (non-blocking)
1. **Proceso, no código** — este ticket ya había pasado por Senior Review + Pre-QA una vez
   (2026-09-22) sobre un alcance distinto al texto vigente. Vale la pena que el batch consolidado
   de Luis a Gerencia1 dejé explícito en Jira qué se resolvió cada vez, para que un futuro recheck
   no vuelva a asumir "ya se hizo" solo por el número de ticket — el texto del ticket puede
   cambiar de alcance entre una pasada y la siguiente sin que el historial de comentarios lo grite.
2. **`AbonosPage.tsx`** — el select de Cliente deriva sus opciones de las filas ya cargadas
   (`abonos`, ya filtradas por los demás criterios activos), igual que `HistorialCobrosPanel.tsx`
   — mismo patrón ya aceptado en ese componente, no es una inconsistencia nueva, solo lo anoto
   porque es la primera vez que se replica fuera de Cobros.

### 🟢 Well done
- El filtro de Cliente en Abonos usa `master_client_id` (id) en vez de texto libre por nombre
  como Cobros — más preciso, evita colisión de nombres duplicados, decisión correcta de no copiar
  ciegamente el patrón de Cobros donde el dominio ya tiene algo mejor disponible.
- El fork frontend detectó y corrigió 3 tests preexistentes que su propio cambio rompía (colisión
  de texto duplicado entre celdas de tabla y las nuevas opciones de `<select>`) en vez de dejarlos
  rotos o silenciarlos.
- Test de rango de fechas backend cubre el caso límite (inclusive en el borde exacto de
  `desde`/`hasta`), no solo el camino feliz.

## Next steps
✅ Aprobado — listo para deploy a `dev.atlanticerp.ai` y de ahí al gate de Visual Review + Pre-QA
(recheck específico de SCRUM-948, ya con el agente que corrió el batch anterior). `git push`
pendiente de aprobación individual de Luis, como siempre.
