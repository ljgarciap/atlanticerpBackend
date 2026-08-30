# Pre-QA — SCRUM-735 (ventas-diseno:cleanup-test-data)

**Fecha:** 2026-08-07
**Commits verificados:** `c1e1a99` + `96ee7b3` (Senior Review 🟢) + `d32e7d2` (fix propio de esta
pasada de Pre-QA, ver más abajo)
**Entorno:** stack local (`infra/docker-compose.yml`), `APP_ENV=local`, tenant `atlantic`,
comando corrido de verdad con `--env-override` (nunca `--force` contra dev/test/production — ver
"Qué NO hacer" del prompt de activación)

## Particularidad del ticket
Es un comando Artisan (`php artisan ventas-diseno:cleanup-test-data`), sin UI. El "romper la app
real" acá fue correr el comando de verdad contra escenarios armados a mano en Postgres local vía
`tinker` (mismo patrón de `actingForAudit()` que usa `TestDataCleanupServiceTest.php`), no solo
confiar en la suite de PHPUnit (13/13 verde al llegar, ya verificada por Senior Review).

## Escenarios de ruptura intentados

| # | Escenario | Resultado |
|---|---|---|
| 1 | Guard de entorno: correr local sin `--env-override` | ✅ Aborta con mensaje claro, exit code 1, no toca la BD |
| 2 | Usuario inexistente (`--user=noexiste@...`) | ✅ `RuntimeException` capturada, mensaje limpio, exit code 1 — sin stack trace crudo |
| 3 | Sin candidatos (usuario real sin actividad en `audit_logs`) | ✅ Reporte vacío y legible, `rows_considered: 0`, sin excepción |
| 4 | Entrelazamiento real: `SubClient` "de Daniela" referenciado por un `SalesProject` real (ajeno, `created_at` fuera de ventana) | ✅ `sub_client` excluido por `active_reference`; cascadea correctamente y también excluye el `master_client` padre (nadie se auto-descubrió como bug — comportamiento conservador correcto) |
| 5 | `CatalogProduct` "de Daniela" referenciado cross-schema por una tabla **distinta** a la que ya cubre el PHPUnit test (usé `servicios.ticket_products`, el test unitario solo cubre `compras.purchase_order_lines`) | ✅ Excluido por `active_reference` — confirma que el fix del gap de Senior Review (commit `96ee7b3`) generaliza de verdad a las 13 tablas documentadas, no solo a la probada en PHPUnit |
| 6 | `QuoteItem` "de Daniela" sin marcador propio, colgando de un `Quote` con `[QA-VD]` | ✅ Excluido por herencia de marcador (`labelsFor()`); además confirmé un efecto secundario correcto no cubierto por PHPUnit: el `master_client`/`sub_client` de los que cuelga ese `Quote` marker-protegido también quedan protegidos por `active_reference`, porque el `Quote` (no candidato) sigue apuntándolos |
| 7 | Doble `--dry-run` sin cambios en el medio | ✅ `diff` byte a byte de las dos corridas: idéntico — determinismo confirmado |
| 8 | `--json` vs tabla de consola | ✅ Mismo contenido en ambos formatos (usuario, ventana, candidatos+IDs, excluidos+motivo+label) |

## Hallazgo real (encontrado, corregido, re-verificado — loop cerrado)

**MEDIO — `execute()` subreportaba el conteo de filas borradas cuando una cascada de Postgres se
adelantaba al paso explícito de `DELETE_ORDER`.**

`sales_projects.sub_client_id` es `cascadeOnDelete()`, y `sub_client` se borra ANTES que
`sales_project` en `DELETE_ORDER` (que siempre lo deja al final, a propósito, por la razón inversa
documentada en el docblock). Cuando ambos son candidatos del mismo owner — el caso típico, lo
reproduce cualquier pipeline completo que Daniela haya creado — borrar el `sub_client` se lleva
puesto el `sales_project` por cascada antes de que `execute()` llegue a su propio paso explícito.
`delete()->rowCount()` en ese paso ve 0 filas (ya no existen), así que el resumen de `--force`
reportaba `sales_project: 0` pese a haber borrado 1 fila real — contradiciendo tanto el estado real
de la BD como el propio `Log::info()` de la línea siguiente (que sí lista el id, porque el
`foreach` sobre `$ids` no depende del resultado de `delete()`).

Reproducido en vivo (no solo inferido leyendo código): armé un pipeline completo
(`MasterClient→SubClient→SalesProject→PipelineCard→Quote→QuotePart→QuoteItem`) de Daniela sin
marcador, corrí `--force`, y el JSON mostró `"sales_project": 0` en `deleted` mientras
`storage/logs/laravel.log` sí tenía la línea `ventas_diseno.cleanup_test_data.deleted
{"entity_type":"sales_project","id":17}` y la fila efectivamente ya no existía en la BD.

**No es un problema de integridad de datos** — nada queda huérfano, nada se pierde, el guard de
`active_reference` sigue funcionando correctamente (escenarios 4-6 arriba). Es un defecto de
exactitud en el propio reporte que esta herramienta le da al operador — relevante porque el Senior
Review dejó explícito que el borrado real contra `test.atlanticerp.ai` "sigue pendiente de una revisión
humana explícita del reporte" — un conteo que miente en el caso más común (pipeline completo) le
resta confiabilidad exactamente al mecanismo de verificación que se supone lo hace seguro.

**Fix aplicado (commit local `d32e7d2`, sin pushear):** en `execute()`, reportar
`count($ids)` en vez del valor de retorno de `delete()` — `buildReport()` ya garantiza que todo id
del set de candidatos termina fuera de la BD al cerrar la transacción (por delete explícito o por
cascada), así que `count($ids)` es el número real de filas afectadas por la corrida, consistente
con `Log::info()`. El fix es genérico (aplica a los 11 entity_types del loop), no un parche puntual
para `sales_project` — cubre cualquier otra cascada equivalente entre las entidades de
`DELETE_ORDER` que no haya sido descubierta todavía.

**Loop cerrado en la misma pasada** (regla dura del rol, sin dejarlo "documentado, sigo con el
próximo"):
1. Confirmé el bug en vivo contra el stack local (no solo lectura de código).
2. Apliqué el fix.
3. Agregué `test_deleted_count_no_subreporta_filas_borradas_por_cascade()` a
   `TestDataCleanupServiceTest.php` — reproduce exactamente este escenario.
4. Revertí el fix temporalmente (`git stash`) y confirmé que el test nuevo FALLA sin él
   (`Failed asserting that 0 is identical to 1`) — no es un test que pasa por casualidad.
5. Restauré el fix (`git stash pop`).
6. Re-corrí el checklist COMPLETO: `infra/test.sh --filter=TestDataCleanupServiceTest` → 14/14 ✅,
   `infra/test.sh` completo (toda la suite del backend) → 1660/1660 ✅, `phpstan analyse
   --memory-limit=1G` (scope real de CI, `paths: [app]`) → 0 errores ✅.
7. Re-verifiqué manualmente contra el stack local con un pipeline completo nuevo: `--force` ahora
   reporta `sales_project: 1` (antes `0`), confirmé con `tinker` que las 7 filas involucradas
   (`master/sub/project/card/quote/part/item`) están realmente borradas.
8. Commit local `d32e7d2` — **sin push**, según instrucción explícita del prompt de activación
   (este batch todavía no tiene OK de Luis para pushear).

Nota lateral: al correr `phpstan analyse` apuntando explícitamente al archivo de test (fuera del
scope normal `paths: [app]` de `phpstan.neon`), aparecen 4 errores preexistentes
(`method.nonObject` en las 4 llamadas `$this->artisan(...)->assertExitCode(...)`, un patrón de
Larastan/PHPStan con el tipo de retorno de `$this->artisan()`). Confirmé que **ya existían antes de
esta pasada** (mismo resultado corriendo phpstan contra el archivo original sin mis cambios) y que
**no están en el scope real de CI/Senior Review** (`vendor/bin/phpstan analyse` sin argumentos, tal
como lo corre CI, solo escanea `app/` — 0 errores). No es un hallazgo de esta pasada, no bloquea,
lo dejo anotado por transparencia nomás.

## Paso 0 — permisos angostos / valores paramétricos / acceso especial
Este ticket no tiene permiso de UI que verificar (es un comando Artisan). Ítem pendiente de
documentación (no bloqueante, dejado explícito según Paso 0 del rol): no encontré un runbook
existente en el repo (`docs/release-process.md` no está trackeado — gitignored, ver
`reference_docs_gitignored_atlanticerp_backend` en memoria) que documente exactamente cómo Luis va a
correr este comando contra `test.atlanticerp.ai` en el futuro (qué contenedor, `docker compose -f
infra/docker-compose.qa.yml exec laravel php artisan ventas-diseno:cleanup-test-data`, con qué SSH
key — `reference_ssh_dev.md` solo documenta `dev.atlanticerp.ai` explícitamente, aunque hay precedente de
uso ad-hoc de SSH+tinker contra `test.atlanticerp.ai` en sesiones previas). Sugerencia para Tech
Writer/PM: un runbook de una página en `docs/runbooks/` (si se decide trackear) antes de que Luis
lo corra por primera vez contra el entorno real.

## Lo que sí funciona
- Guard de entorno (`qa` únicamente salvo `--env-override`) — bloquea de verdad, no es cosmético.
- Manejo de usuario inexistente — error limpio, sin stack trace.
- Reporte vacío legible cuando no hay candidatos.
- Exclusión por marcador (`[DEMO-711]`/`[QA-VD]`/`[QA-VD-409]`), incluida la herencia por cadena de
  FKs hacia arriba (hijo sin marcador propio, protegido por el marcador de un ancestro).
- Guard de `active_reference` para `MasterClient`/`SubClient`/`SalesProject` — incluida la cascada
  de exclusión (un padre se excluye si un hijo vivo no-candidato todavía lo referencia, aunque el
  padre mismo no tenga marcador).
- Guard de `active_reference` cross-schema para `CatalogProduct` — verificado con una tabla
  distinta a la que cubre el test unitario (`servicios.ticket_products` además de
  `compras.purchase_order_lines`), confirma que las 13 tablas documentadas están realmente
  cubiertas, no solo la que ejercita PHPUnit.
- Determinismo: dos `--dry-run` seguidos sin cambios en el medio producen el mismo reporte byte a
  byte.
- Paridad `--json` / tabla de consola — mismo contenido en ambos formatos.
- `--force` respeta exactamente el reporte de `buildReport()` — nunca borra algo que el reporte
  excluyó, ni deja de borrar algo que el reporte aprobó (después del fix, el conteo también refleja
  correctamente lo que borró, incluso vía cascada).

## Veredicto
✅ **Pasada limpia tras 1 vuelta de loop** (hallazgo MEDIO encontrado, corregido, re-verificado con
la suite completa en la misma sesión — no quedó como deuda documentada sin cerrar). Pasa a `QA`.

El borrado real contra `test.atlanticerp.ai` sigue pendiente de OK explícito de Luis (nunca se corrió
`--force` fuera de fixtures locales desechables ni de la suite de PHPUnit) — igual que dejó
explícito el Senior Review. Este Pre-QA no cambia eso.
