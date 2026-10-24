# Desglose del PM — SCRUM-1137: staging completo de Clientes de ICG y corrección de RUC/DV

Estado: **BORRADOR del PM, pendiente de aprobación de Luis** (2026-10-13). No se creó ningún subtask en Jira ni se asignó nada.
Insumos: spec `docs/specs/icg-clientes-staging-completo.md` (repo exterior), diseño `docs/architecture/icg-clientes-staging-completo-diseno.md`, esquema real `docs/architecture/icg-clientes-esquema-icg.txt`.
Tamaños: S (< medio día), M (~1 día), L (varios días). Son estimaciones relativas, no compromisos.

> **CAMBIO DE ALCANCE (Luis, 2026-10-13): se elimina la consulta a la DGI.** La Fase 4 completa queda anulada. Ya no se consulta a la DGI, no se completa ningún DV y no se guarda el nombre oficial. La extracción trae el dato de `NIF20`, lo normaliza en RUC y DV, y actualiza la base de datos (copia cruda, normalización y corrección de los `sub_clients` ya promovidos). Las cifras sobre cuántos `sub_clients` son consultables a la DGI (~6.050) ya no aplican.

## 1. Reglas de ejecución (aplican a todas las fases)

- Un batch por fase. Cada fase pasa por Senior Review → Pre-QA → QA antes de avanzar a la siguiente. Sin Visual Review: no hay UI.
- Ciclo interno: `infra/test.sh --filter=<clase>` y `vendor/bin/phpstan analyse --memory-limit=1G`. Gate real antes de push: `infra/gate.sh` (suite completa). Nunca `docker compose exec ... artisan test` directo.
- Migraciones revisadas por el Arquitecto antes de aplicarse.
- Backend único rol de código; Frontend no participa. Si 2+ agentes tocan el repo a la vez, `isolation: worktree` (1 repo por despacho). Antes de despachar: `git status` y `git worktree list` (el checkout se comparte con otro equipo).
- Todo push pasa por el gate de aprobación. Todo fix de Pre-QA se pushea a `dev`.
- Orden de entornos: dev → test → prod. Cada corrida real lleva su dry-run y su CSV revisado antes. Cualquier comando contra prod por `ssh` lo corre Luis con `!`.
- El Tech Writer documenta en paralelo, no al final.

## 2. Fase 0 — Verificaciones previas (sin código, en paralelo, bloquean el arranque de F3/F4)

| # | Tarea | Rol | Tamaño | Bloquea |
|---|---|---|---|---|
| T0.1 | Revisión de seguridad de F1: columnas bancarias copiadas, acceso restringido a `superadmin.all`, ningún endpoint expone `*_raw`, retención. Entrega threat model corto en `docs/security/` | Cybersecurity | S | F1 (ejecución en datos reales) |
| T0.2 | Contar en AtlanticERP cuántos `sub_clients` promovidos tienen el `tax_id` truncado (SQL solo lectura): dev y test por el DevOps; **prod lo corre Luis con `!`**. Reemplaza la cifra de ~3.500, que sale de ICG | DevOps | S | Dimensionar F3 |
| T0.3 | Leer el código y confirmar si facturas, cotizaciones, órdenes de compra y facturación electrónica leen `sub_clients.tax_id` en vivo o guardan snapshot | Backend | S | F3 en prod |
| T0.4 | Confirmar que `LogsAuditTrail` registra `tax_id` y `tax_id_dv` en `old_values/new_values` de `audit_logs` | Backend | S | F3 |
| T0.5 | Decisiones de Luis: quién revisa los casos raros tras el corte; quién valida en test | Luis | — | Cierre de F3 |

### Resultados de la Fase 0 (2026-10-13)

**T0.1 — Cybersecurity:** revisión corta escrita en `docs/security/REVIEW-icg-clientes-staging-f1.md` (repo exterior). Sin riesgos críticos ni altos; 6 condiciones. Pendiente de que Luis la acepte. La condición 6 (retención de las tablas `*_raw` tras el corte de ICG) es decisión de Luis.

**T0.2 — Conteo en AtlanticERP (solo lectura). Prod medido 2026-10-13: 5.873 matched / 1.646 promoted / 250 unmatched / 27 ambiguous; de los promovidos, 152 truncados corregibles, 6 con `tax_id` distinto del `CIF` (posible edición manual), 2 con `tax_id_dv` ya cargado, 1.472 sin `NIF20`. Los números de abajo son de dev y test, casi idénticos.**
- `legacy.clientes`: 7.796 = 5.866 matched + 1.644 promoted + 259 unmatched + 27 ambiguous (test: 5.867 / 1.644 / 258 / 27).
- De los 1.644 promovidos: **154 tienen el `tax_id` truncado y corregible desde `NIF20`** (el `tax_id` es el `CIF` y el `NIF20` lo extiende); 16 ya tienen `tax_id` = `CIF` = `NIF20`; 3 tienen un `tax_id` distinto del `CIF` (posible edición manual o placeholder); 1.471 no tienen `NIF20` (probablemente los placeholders `SIN-CIF-…`; no lo verifiqué uno por uno); 0 con `NIF20` que no extienda.
- **Esto corrige la estimación de ~3.500 de la spec y el diseño: esos 3.500 son clientes de ICG con `CIF` truncado, pero la gran mayoría está `matched` con un `sub_client` que ya existía en AtlanticERP con su propio `tax_id`, no es un `tax_id` copiado del `CIF`.** El alcance real de F3 en dev/test es ~154, no ~3.500.
- Ejemplos con DV inconsistente en ICG para el mismo RUC: `1099483-1-559366` aparece con y sin `DV71` (clientes 1137 y 1929); `1160410-1-573597` aparece con `DV2` y `DV23` (clientes 3806 y 2313). Confirma la decisión D5 (manda la DGI) y que dos clientes de ICG pueden compartir RUC.
- `sub_clients` en dev: 7.537 en total, 7.525 sin `tax_id_dv` (99,8 %), 1.474 con placeholder `SIN-CIF-…`, 1 consumidor final. **6.050 son consultables a la DGI** (con RUC real, sin DV y no consumidor final). **El alcance real de F4 es ~6.050 consultas, no ~3.500: a 20 por minuto son unas 5 horas.**

**T0.3 — Quién lee `sub_clients.tax_id`:**
- **En vivo (cambia al corregirlo):** `InvoiceBookService` (libro de facturas, `ruc`), `InvoiceService` (`cliente_ruc` del payload), `ReceptorFiscalGuard` y `FiscalDocumentBuilder` (arma el documento fiscal al certificar).
- **Snapshot:** `QuoteService` copia el RUC a `quotes.ruc` al crear la cotización; las cotizaciones ya creadas no cambian.
- Consecuencia: corregir un `tax_id` cambia el RUC que muestra el libro de facturas para facturas históricas de ese cliente (de truncado a completo). Hay que avisarlo a Contabilidad. **No verifiqué** si los documentos fiscales ya certificados guardan el RUC enviado a la DGI aparte; Pre-QA de F3 debe comprobarlo antes de prod.

**T0.4 — Auditoría:** `LogsAuditTrail` registra `created/updated/deleted` de `SubClient` con valores anteriores y nuevos de todos los atributos salvo los timestamps (acción `ventas_diseno.sub_client.updated`). Cubre `tax_id` y `tax_id_dv`, **siempre que se use `save()` de Eloquent**: un `UPDATE` por query builder no audita. F3 y F4 deben usar Eloquent.

**T0.5 — Pendiente de Luis:** quién revisa los casos raros y quién valida en test.

## 3. Fase 1 — Copia cruda (no cambia el comportamiento de AtlanticERP)

Depende de T0.1 solo para ejecutar con datos reales; el código se puede construir antes.

| # | Tarea | Rol | Tamaño | Depende de |
|---|---|---|---|---|
| T1.1 | Migraciones de `legacy.clientes_raw` (110 columnas, llave `codcliente`) y `legacy.contactos_cliente_raw` (llave `id`), generadas desde `icg-clientes-esquema-icg.txt`, con `source_hash` y timestamps. Sin FK a `ventas_diseno` | Backend | M | — |
| T1.2 | `ClienteRawExtractor` y `ContactoClienteRawExtractor` (extienden `IcgTablaExtractor`), con la lista de columnas excluidas (`CVC`, `PASS`, `PASSWORDCOMMERCE`, `NUMTARJETA`, `FOTOCLIENTE`) y error explícito si `CONTACTOSCLIENTES.ID` llega nulo | Backend | M | T1.1 |
| T1.3 | Registrar las dos entidades en `AlmacenEntidades`/`AlmacenStagingLoader` (o un loader gemelo si no encaja) con hash de cambio | Backend | M | T1.1 |
| T1.4 | Comandos `legacy:sync-clientes-raw {--tenant} {--dry-run}` y `legacy:verify-clientes-raw`, con registro en `legacy.sync_runs` (entidad `clientes_raw`) | Backend | M | T1.2, T1.3 |
| T1.5 | Tests: mapeo de filas con fixtures, columnas excluidas ausentes, idempotencia, corte de conexión a mitad (queda `partial`), fila borrada en ICG reportada por `verify` | Backend | M | T1.4 |
| T1.6 | Documentar en el CLAUDE.md del módulo Legacy (1 línea) y en `docs/changelog.md` el detalle; runbook de la corrida | Tech Writer | S | T1.4 |
| T1.7 | Corrida real: dev (dry-run → real → `verify`), test, luego prod con aprobación. Medir memoria y duración | DevOps | S por entorno | Gates de F1 |

**Gate F1 / casos que debe romper Pre-QA:** conteos 7.796 y 821 coinciden con ICG; ninguna columna excluida aparece en las tablas; segunda corrida no cambia nada; ICG caído a mitad; `ID` nulo; no choca con el sync nocturno (no correr en las ventanas 21:00 prod / 01:00 qa / 05:00 dev ni a las 03:00).

## 4. Fase 2 — Normalización del RUC (cambia el comportamiento para clientes nuevos o por promover)

T2.1 no depende de F1 y puede avanzar en paralelo a ella.

| # | Tarea | Rol | Tamaño | Depende de |
|---|---|---|---|---|
| T2.1 | `TaxIdNormalizer` (clase pura) con tests sobre los valores reales medidos: `155716554-2-2021`, `155783782-2-2026DV35`, `987695-1-533551 DV09`, `1671600-1-679822`, `6-712-881`, `RUC 1671600-`, `155662320 2 `, `CIF` de 12 caracteres sin `NIF20`. Devuelve `ruc`, `dv`, `origen`, `estado` | Backend | M | — |
| T2.2 | Migración: `legacy.clientes` gana `nif20`, `ruc`, `ruc_dv`, `ruc_origen`, `ruc_estado`. `ClienteExtractor` lee `NIF20` | Backend | S | — |
| T2.3 | **Riesgo del circuit breaker:** agregar `nif20` al hash cambia el `source_hash` de las 7.796 filas, y el incremental (umbral 20 %) las vería todas cambiadas y quedaría `held_for_review` sin correr el Matcher. Definir y construir el re-baseline (una corrida completa de `legacy:sync-clientes` tras el deploy, o una bandera `--rebaseline`) | Arquitecto → Backend | M | T2.2 |
| T2.4 | Cargar `ruc`/`ruc_dv`/`ruc_estado` en ambos loaders (completo e incremental) usando `TaxIdNormalizer` | Backend | M | T2.1, T2.2 |
| T2.5 | `ClienteMatcher`: comparar primero por `ruc`, luego `CIF` normalizado, luego email. Con modo de reporte que cuente cuántos clientes cambian de `match_status` **antes** de activarlo | Backend | M | T2.4 |
| T2.6 | `ClienteFusionLoader`: al promover usar `ruc` y `ruc_dv` (cédulas dejan DV vacío) | Backend | S | T2.4 |
| T2.7 | Tests de regresión: SCRUM-1113 (un `promoted` no pierde su vínculo), un `ambiguous` sigue sin elegir al azar, `NIF20` vacío cae a `CIF` | Backend | M | T2.5, T2.6 |
| T2.8 | Corrida: dev (con el re-baseline), medir el reporte de cambios de matching, test, prod con aprobación | DevOps | S por entorno | Gates de F2 |

**Gate F2 / Pre-QA:** el reporte de cambios de matching lo revisa Luis antes de activar el Matcher nuevo; valor fuera de patrón nunca se normaliza solo; el incremental diario no cae en `held_for_review` tras el deploy.

## 5. Fase 3 — Corrección de los `sub_clients` ya promovidos (toca datos vivos)

No arrancar sin T0.2, T0.3 y T0.4 cerradas y F2 en dev.

| # | Tarea | Rol | Tamaño | Depende de |
|---|---|---|---|---|
| T3.1 | Migración `legacy.cliente_ruc_correcciones` (run_id, sub_client_id, `tax_id` y `tax_id_dv` anterior y nuevo, operador, fecha) | Backend | S | — |
| T3.2 | `legacy:fix-clientes-ruc {--tenant} {--dry-run \| --apply --operador=} {--revert=<run_id>}`. Dry-run por defecto. Candidatos solo con las 3 precondiciones del diseño. Aplicación con `SubClient::save()` (auditoría). CSV con valor actual y propuesto, y los casos `revisar` | Backend | L | T3.1, F2 |
| T3.3 | Tests: precondición no cumplida (editado a mano) se omite; `--dry-run` no escribe; `--revert` restaura exacto; casos raros 7340, 7759, 5620 intactos; idempotente al segundo `--apply`; cliente con `NIF20` más largo y DV | Backend | M | T3.2 |
| T3.4 | Runbook de uso y reversa en `docs/runbooks/` | Tech Writer | S | T3.2 |
| T3.5 | Corrida dev → CSV revisado por Luis → test → CSV → prod solo con aprobación expresa y con el comando corrido por Luis | DevOps | S por entorno | Gates de F3 |

**Gate F3 / Pre-QA (bloqueante):** reversa probada en dev de punta a punta; facturación electrónica con un cliente corregido en dev (no rechazo del PAC); nada en prod sin que el CSV de test haya sido revisado.

## 6. Fase 4 — DGI

**Anulada** (Luis, 2026-10-13). No hay consulta a la DGI en este ticket.

## 7. Orden y paralelismo

```
F0 (verificaciones, en paralelo) ─┬─► F1 ─► F2 ─► F3
                             └─► T2.1 (normalizador puro) puede empezar ya, en paralelo a F1
```
F1 y T2.1 son independientes: pueden ir en dos worktrees distintos. F2 en adelante es secuencial. En cada fase, un entorno a la vez (dev, luego test, luego prod).

## 8. Decisiones (Luis, 2026-10-13)

1. T2.3 — **re-baseline = corrida completa de `legacy:sync-clientes` una vez tras el deploy de F2.** No se agrega código al incremental.
2. T0.1 — **Cybersecurity sí revisa F1** (revisión corta) antes de ejecutarla con datos reales.
3. Jira — **SCRUM-1137 es el único ticket.** Sin subtasks; el avance se comenta en el ticket y el detalle vive en este documento.
4. Despliegue — **F1 va primero a dev, test y prod**, sin esperar a F2–F4.
5. **PENDIENTE:** quién revisa los casos raros 7340, 7759 y 5620 tras el corte (T0.5), y quién valida en test. No bloquea F0–F2.

Aprobación del desglose: pendiente de que Luis confirme que arranca la Fase 0.

## 9. Riesgos del plan

- Hay carga alta en `dev` (otro equipo despliega en el mismo entorno; la consulta a ICG falló una vez por un deploy a mitad). Avisar antes de las corridas largas.
- Cada corrida contra ICG lee tablas completas; no coincidir con los syncs agendados (prod 21:00, qa domingo 01:00, dev domingo 05:00).
- F3 y F4 tocan datos de clientes en prod con facturación electrónica activa; no se pasa de entorno sin gate limpio.
