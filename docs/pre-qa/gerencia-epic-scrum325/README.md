# Épica Gerencia (SCRUM-325) — historial de Pre-QA/Visual Review, recuperado 2026-08-28

Estos 9 reportes (v2 → v9, 2026-08-23 → 2026-08-26) se escribieron originalmente en
`atlanticerp/docs/pre-qa/` (la carpeta padre del workspace, que **no es un repo git** — confirmado con
`git rev-parse --is-inside-work-tree`) porque cada commit de esa época asumía que no había dónde
más comitearlos ("fuera de este repo — atlanticerp/ no es un repo git independiente"). Nunca estuvieron
bajo control de versiones en ningún lado hasta este commit — recuperados y movidos acá para que
dejen de depender de un directorio suelto en el filesystem de Luis.

**Nota de alcance real, repetida en 4 de las 9 pasadas (v2, v3, "14tickets", v7):** la épica
Gerencia (SCRUM-325) se documentó explícitamente como **"epic de otro equipo"** — Pre-QA auditaba a
pedido puntual de Luis, sin implementar fixes ni tocar Jira (primeras pasadas) o comentando/
transicionando Jira pero sin tocar código (v6). Esto cambió sin una decisión explícita registrada:
a partir del batch de fixes del 2026-08-26 (commits `fix(gerencia): ...` en `dev`, ver
`git log dev --oneline | grep gerencia` en ambos repos), sesiones de Claude sí implementaron
fixes de código reales para esta épica, cerrándola 10/10 en `QA`/`UAT` (ver v9). Nunca se
registró en memoria por qué cambió el criterio — ver
`feedback_gerencia_epic_otro_equipo_scope_20260828.md` en la memoria del proyecto para el detalle
completo y la pregunta abierta a Luis.

## Índice cronológico

| Versión | Fecha | Alcance | Resultado |
|---|---|---|---|
| `gerencia-epic-14tickets-20260823.md` | 2026-08-23 | 14 tickets, primera pasada | Reporte puro, sin fixes ni Jira |
| `gerencia-epic-12tickets-20260823-v2.md` | 2026-08-23 | 12 tickets (segunda pasada mismo día) | Reporte puro, sin fixes ni Jira |
| `gerencia-epic-12tickets-20260824-v3-postmerge.md` | 2026-08-24 | 12 tickets, post-merge | Merge+deploy hechos a pedido de Luis, sin tocar Jira |
| `gerencia-epic-12tickets-20260824-v4-final.md` | 2026-08-24 | 12 tickets | — |
| `gerencia-epic-12tickets-20260824-v5-recheck.md` | 2026-08-24 | 12 tickets, recheck | — |
| `gerencia-epic-9tickets-20260825-v6-recheck.md` | 2026-08-25 | 9 tickets, recheck | Sin fixes de código; Jira sí comentado/transicionado |
| `gerencia-epic-10tickets-20260826-v7-recheck.md` | 2026-08-26 | 10 tickets, recheck | 2/10 PASAN, 8/10 NO PASAN — reporte de Pre-QA (comportamiento/datos); el gate de Visual Reviewer del mismo día vive en `atlanticerp-frontend/docs/visual-review/gerencia-epic-10tickets-20260826-v7-recheck.md` (fidelidad visual, reporte separado) |
| `gerencia-epic-10tickets-20260826-v8-recheck.md` | 2026-08-26 | 10 tickets, recheck tras batch de fixes | 9/10 PASAN, 1 (SCRUM-168) vuelve a `In Progress` |
| `gerencia-scrum168-20260826-v9-recheck.md` | 2026-08-26 | Recheck puntual SCRUM-168 | ✅ PASA — cierra la épica 10/10 en `QA` |
