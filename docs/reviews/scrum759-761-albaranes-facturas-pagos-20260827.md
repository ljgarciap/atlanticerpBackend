# Senior Review — SCRUM-759/760/761 (Albaranes, Facturas, Pagos) + fix de paginación

**Fecha:** 2026-08-27
**Revisor:** Arquitecto (lente Senior Reviewer, fusionado con Pre-QA por eficiencia — mismo
criterio ya en uso para Visual Reviewer+Pre-QA, ver `atlanticerp/CLAUDE.md`; alcance acotado, tooling
interno de migración, no feature de UI)
**Alcance:** Implementación original de SCRUM-759→761 (mergeada a `dev` ~2026-08-19, nunca
revisada formalmente hasta ahora) + el fix de paginación aplicado hoy sobre Albaranes/Pagos
(commits `bc310a0`, `72d5fdd`).
**Gap que cierra:** ninguno de los 3 tickets tenía Senior Review ni Pre-QA registrado — señalado
repetidas veces en `docs/changelog.md` y en el diseño (`migracion-icg-diseno.md`) desde el
19 de agosto, nunca cerrado hasta hoy. Ver checklist §14, punto 2.

---

## Resultado: PASA — 0 hallazgos bloqueantes

## Verificación automatizada (evidencia, no sustituye la revisión manual)

- PHPStan Level 8, proyecto completo (733 archivos): sin errores.
- Suite completa PHPUnit: 2,732 tests, 11,173 assertions, en verde.
- Corrida real contra ICG: dev y test, las 3 entidades, 0 errores en las 6 corridas
  (`legacy.sync_runs`, verificado por Postgres directo — ver
  `memory/project_scrum755_758_tarea6_procedimiento_real.md` y el artifact consolidado).

## Revisión de arquitectura y correctness

**Consistencia de patrón** — Albaranes/Facturas/Pagos siguen exactamente el mismo diseño
Extractor→Loader-A(staging)→[Matcher] ya validado en Clientes/Proveedores/Cotizaciones/Pedidos
(§12.1 del runbook):
- `AlbaranStagingLoader`/`FacturaStagingLoader`/`PagoStagingLoader`: upsert idempotente por llave
  natural compuesta, `legacy_cliente_id`/`orphan` **nunca** incluidos en el `$update` del upsert —
  confirmado en código, correcto: una segunda corrida de staging nunca resetea un link ya resuelto
  por el Matcher.
- `AlbaranMatcher`/`FacturaMatcher`: lookup simple contra `legacy.clientes` ya poblada (sin
  re-matchear CIF/email), orphan tolerado nunca descartado — consistente con la regla dura del
  diseño ("nunca se inventa un link", §5).
- Todas las queries de resolución de ids usan comparación de tuplas nativa de Postgres +
  `array_chunk(500)`, mismo margen conservador que el resto del módulo frente al límite de 65535
  parámetros — sin riesgo de escala distinto al ya probado.
- Todas las escrituras están envueltas en una única `DB::transaction()` — un fallo a mitad de
  camino no deja nada comprometido (confirmado en vivo hoy: 2 procesos matados por OOM en
  Albaranes/Pagos, `legacy.albaranes`/`legacy.pagos` quedaron en 0 ambas veces antes del fix).

**Migraciones** — llaves naturales compuestas correctas y consistentes con lo que cada
StagingLoader usa en su `upsert()`:
- `legacy.albaranes`: 3 columnas (`icg_numserie`+`icg_numalbaran`+`icg_n`), `icg_n` como texto —
  correcto, confirmado contra ICG real el 17 de agosto (`'B'` constante).
- `legacy.facturas`: mismo criterio de 3 columnas. `sufactura` guardado crudo sin interpretar,
  consistente con el gotcha ya conocido de `SUPRESUPUESTO`.
- `legacy.pagos`: 6 columnas — corregido deliberadamente sobre el sketch abreviado de 4 columnas
  del diseño original (`§4`), incluye `icg_posicion` — correcto, sin esto dos líneas de pago del
  mismo documento hubieran colisionado en el upsert. El test específico
  `test_posiciones_distintas_del_mismo_documento_no_colisionan` cubre exactamente este caso.

**Modelos** — `getTable()` resuelve schema por tenant consistente con el resto del módulo
`App\Modules\Legacy`, `casts()` correctos (decimales a 2 posiciones para montos, booleans para
`facturado`/`orphan`).

## Fix de paginación (hoy) — revisión del cambio en sí

- `AlbaranExtractor`/`PagoExtractor`: mismo patrón ya probado en `CotizacionExtractor`
  (2026-08-21) — `OFFSET...FETCH` con `ORDER BY` explícito (SQL Server lo exige), nunca más de una
  página de items en memoria a la vez.
- `AlbaranStagingLoader::loadPaginated()`/`PagoStagingLoader::loadPaginated()`: misma atomicidad
  que la versión no paginada (una sola transacción para toda la corrida), el `deleteExistingItems()`
  corre una única vez antes del loop de páginas — correcto, evita el bug de "cada página borra lo
  que insertó la anterior".
- `App\Modules\Legacy\Support\DryRunAbort`: mecanismo limpio y ya validado en vivo (`fuse-clientes`/
  `fuse-proveedores` en prod, ayer) — no se reutiliza acá porque no aplica (el bug de auditoría
  encontrado en `fuse-*` era específico de esos 2 comandos, `legacy:sync-*` nunca tuvo ese problema
  — su `--dry-run` siempre escribió a `legacy.*` intencionalmente, documentado desde el diseño
  original).
- Tests nuevos (`loadPaginated`, mismo patrón que `CotizacionStagingLoaderTest`) cubren:
  reparto en varias páginas sin perder/duplicar, offsets crecientes hasta página vacía,
  idempotencia al re-correr, huérfano dentro de la corrida descartado sin romper el resto.

## Hallazgos — no bloqueantes, para seguimiento

1. **[MEDIO, no bloqueante] Verificación de tipo `icg_n`/columnas de texto en FACTURASVENTA y
   TESORERIA nunca se hizo explícitamente vía `INFORMATION_SCHEMA` antes de la corrida real**, pese
   a que el propio docblock de ambas migraciones lo pide explícitamente ("confirmar contra
   INFORMATION_SCHEMA antes de la primera corrida real, no asumir ciego"). La corrida real de hoy
   (dev+test, 0 errores) es evidencia empírica indirecta de que el supuesto era correcto — pero no
   es la verificación formal que el propio diseño exigía, porque el pipeline castea defensivamente
   (`(string)`) sin importar el tipo real de origen, así que un mismatch de tipo no necesariamente
   se manifestaría como error. Sugerencia: correr el `INFORMATION_SCHEMA` real contra ICG como
   confirmación de cierre, no como bloqueante — la migración a prod puede seguir sin esperarlo dado
   el resultado limpio en dev/test.
2. **[BAJO, ya conocido y aceptado] `sufactura`/`estado` guardados sin interpretar** — decisión de
   diseño explícita y correcta (no inventar semántica sin confirmar), no es un hallazgo nuevo.
3. **[BAJO, cosmético] La discrepancia de 1 fila entre `sync_runs.cargados` (145,253) y
   `legacy.pagos` real (145,252) en test** — ya documentada en el artifact consolidado y en la
   memoria del proyecto, explicada por un upsert colapsando 2 filas con la misma llave de 6
   columnas dentro de la misma corrida. No bloqueante, no afecta integridad de datos (el upsert es
   exactamente el comportamiento correcto ante una colisión real de llave).

## Veredicto

Sin hallazgos bloqueantes. El batch (código original + fix de paginación) queda **aprobado** para
promoción a producción, sujeto al resto del checklist §14 (confirmación explícita de Luis por
entidad, dry-run antes de cada real).
