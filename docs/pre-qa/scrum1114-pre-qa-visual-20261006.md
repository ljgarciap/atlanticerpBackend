# SCRUM-1114 — Pre-QA + Visual Review fusionados (2026-10-06)

Entorno: https://dev.atlanticerp.ai (backend 231d8816, frontend 03ecb67). Cuentas: superadmin luis.garcia@ (login con su password por defecto, sin cambiar nada), no superadmin fefi@ (vendedor_disenador) y daniela@ (management).
Evidencia: capturas en `docs/pre-qa/scrum1114-20261006/` y specs temporales sin commitear `atlanticerp-frontend/e2e/_preqa_scrum1114*.spec.ts`.
SMTP de dev = Mailpit (`MAIL_HOST=mailpit`), nunca llega a personas reales. No se ejecutó ninguna corrida real, --force, reversa ni init-baseline. Estado de ajustes restaurado al final (activo=false, umbrales 30/2000/30000/80/48, correos []). Sin ban de CrowdSec.

## Veredicto: PASADA LIMPIA con 4 observaciones Menores/Mayor-UX no bloqueantes (ver hallazgos). Ningún Bloqueante.

## Criterios 1-13
| # | Criterio | Escenario de ruptura intentado | Resultado | Evidencia |
|---|---|---|---|---|
| 1 | Línea base | Verificar base = lo cargado, en todos los ligados | PASA (en dev) | 11.707 ligados, 11.707 con `stock_al4_aplicado`, 0 sin base; `Σ base = Σ stock_quantity = 78.467`, 0 diferencias base vs stock_quantity. T1 de prod ya documentado en la spec |
| 2 | Ejemplo canónico 100/90/-5 | Reproducir en dev real | NO PROBADO en dev (prohibido mutar datos/correr real). Cubierto por `test_ejemplo_canonico_icg_baja_10_y_atlanticerp_movio_5_queda_en_85` | Fórmula coherente con datos reales: la corrida #76 dejó `saldo_inicial + cantidad = saldo_resultante` en 3.502/3.502 movimientos |
| 3 | Delta positivo | idem | NO PROBADO en dev; test `test_delta_positivo_...`. En datos reales: 79.953 unidades positivas aplicadas | Informe corrida #76 |
| 4 | Sin cambios → idempotente | `--dry-run` inmediato tras la corrida real | PASA | Dry-run: 11.707 sin cambio, 0 delta, 0 movimientos; ninguna fila nueva en kardex |
| 5 | Atomicidad | Forzar fallo | NO PROBADO en dev; test `test_una_falla_en_un_producto_revierte_su_movimiento...`. Real: 0 errores, 0 movimientos duplicados por producto, 0 filas inconsistentes | SQL solo lectura |
| 6 | Producto sin fila (NULL → valor) | Datos reales | PASA | 3.470 productos "desde 0": Central pasó a 3.529 filas; 3.502 movimientos de kardex |
| 7 | Negativos | Datos reales | PASA | 226 productos en negativo (Σ -1.486) aplicados sin truncar y listados en el informe (`negativos_ids`, 100 ids mostrados) |
| 8 | Un solo camino de escritura (Σ bodegas = stock_quantity) | Consulta sobre los 11.707 ligados | PASA | 0 no cuadran; el informe de la corrida y de la pantalla también dicen 0 |
| 9 | Alcance (Lafayette, inactivos, no ligados) | Buscar movimientos fuera de alcance | PASA | 0 movimientos sync sobre inactivos/descatalogados/no ligados; todos en bodega 6 (Central); 0 descatalogados en dev (no hay caso negativo real: cubierto por test) |
| 10 | Circuit breaker + umbrales editables | Editar umbrales por UI y API; topes | PASA | La corrida #76 quedó con `circuit_breaker_forzado` (2.762 > 2.000) y se aplicó con --force: el breaker disparó. Umbrales editados por UI y persistidos tras recarga; topes 50/50.000/500.000 validados en servidor y cliente. Aviso: correo (Mailpit) + log `legacy-sync-evento` |
| 11 | Rendimiento | `--dry-run` con memory_limit 256M y 64M | PASA | 64.036 filas de AL4, 12 s, exit 0 incluso con `memory_limit=64M`; paso propio como proceso aparte (décimo de `legacy:sync-incremental-all`); scheduler de dev dom 05:00, sin solaparse con test (dom 01:00) ni con jobs de 03:00 |
| 12 | Auditoría y reversa | Revisar `audit_logs` | PASA (auditoría) / reversa NO PROBADA (prohibida) | `legacy.sync_stock_delta` (corrida), `legacy.init_stock_baseline`, y 15 eventos `legacy.stock_sync_settings.updated` de mis pruebas; movimientos con `referencia_entidad_id` = id de corrida (#76), `realizado_por` nulo |
| 13 | Tests de regresión | Revisar suite | PASA (lectura) | Existen los tests 100/90/-5, delta positivo, idempotencia (`LegacySyncIncrementalStockCommandTest`) y `test_un_cambio_de_solo_stock_al4_no_cuenta_como_cambiado` (`ProductoIncrementalStagingLoaderTest`). No se corrió la suite (prohibido infra/test.sh) |

Extras de la spec:
- Kardex: 3.502 movimientos, todos tipo `Ajuste`, `referencia_entidad_tipo='legacy.sync_run'`, texto "Ajuste por sincronización ICG (corrida #76)", ningún Entrada/Salida. PASA.
- `legacy:check-sync-health` incluye `stock` en ENTITIES y no marca "saltada" con `activo=false` (código). `legacy:sync-incremental-all` lo tiene como décimo paso tras productos (código). PASA.
- Spec de seguridad: cada cambio de ajustes registra correo/evento (log `legacy-sync-evento: Ajustes de la sincronización de stock modificados` con quién, IP y diff) + `audit_logs`. PASA. (No se leyó la bandeja de Mailpit: exige credenciales; confirmado por log.)

## A) Pantalla de superadmin (Playwright, dev)
| Prueba | Resultado |
|---|---|
| Panel "Sincronización de stock (AL4)" en Migración ICG: línea base inicializada (fecha), interruptor, 5 umbrales, correos, tabla de últimas corridas y "Informe de la última corrida" (entraron +79.953, salieron -1.486, desde 0 3.470, negativos 226, no cuadran 0) | PASA |
| Editar umbral + correo, guardar, recargar: persiste; los demás campos intactos (30/30000/80/48) | PASA |
| Interruptor activo: guardar → "activas"; revertir | PASA |
| Validaciones de cliente: % 51/-1/vacío/"12,5" bloquean; 50 permite; por producto 50001/0/1,5 bloquean, 50000 permite; total 500001/abc bloquean, 500000 permite; correos "foo"/"a@x" bloquean, 21 correos bloquean; el botón "Guardar ajustes" queda deshabilitado | PASA (ver M1) |
| Doble clic en Guardar | PASA: 1 solo PUT |
| NO superadmin (fefi): sin ítem Migración en el menú; `/migracion/dashboard` redirige a `/ventas-diseno/home`, sin panel | PASA |
| Emojis / ortografía / truncados | PASA: sin emojis, textos en español correctos, sin cortes |
| Responsive 390 px y 820 px: sin scroll horizontal de página; modo oscuro: legible, contraste correcto | PASA |

## B) API
- 403 para fefi (vendedor_disenador) y daniela (management) en GET y PUT; 401 sin token. PASA.
- 422 (mensaje en español): pct 51/-1/"abc", por producto 50001/0/10,5, total 500001, min_filas 0, horas 721, activo "maybe", correos repetidos distinguiendo mayúsculas (A@X.com vs a@x.com), correo inválido, "", null, no-array. PASA.
- `baseline_inicializada_at` enviado en el PUT: 200 pero ignorado (no cambió). PASA (ver m2).
- PUT activo=true con base inicializada: 200; vuelto a false. Cambios parciales no pisan otros campos. PASA.
- 5 PUT concurrentes: todos 200, valor final consistente (30005), 5 eventos de auditoría. PASA (ver m3).
- GET `/api/legacy/migracion/dashboard/runs?entidad=stock`: 200 con la corrida #76 y su resumen. PASA.
- GET `/stock-sync`: 200, 0,28 s, 3,3 KB.

## Hallazgos
Ningún Bloqueante.

| ID | Sev. | Hallazgo | Reproducir |
|---|---|---|---|
| M1 | Mayor (UX, no bloqueante, recomiendo corregir antes de QA/marly) | Cuando un valor es inválido, el botón "Guardar ajustes" queda deshabilitado SIN mensaje alguno que diga qué campo y por qué (el único `role="alert"` es para errores del servidor). Un superadmin que escribe `12,5` (coma decimal, normal en español) o un correo mal escrito no sabe por qué no puede guardar. | Panel: poner `51`, `12,5` o `foo` en correos; 0 alertas visibles, botón gris |
| m1 | Menor | `a@x` (sin punto) lo rechaza la pantalla pero el servidor lo acepta (`email:rfc`), quedó guardado en la BD vía API; las dos validaciones no coinciden. Un destinatario así hace fallar el envío de alertas. | PUT `{"correos_alerta":["a@x"]}` → 200 |
| m2 | Menor | El PUT con `baseline_inicializada_at` o campos desconocidos responde 200 ignorándolos en silencio (no 422). Seguro (no cambia nada) pero engañoso para un cliente de la API. | PUT `{"baseline_inicializada_at":null}` |
| m3 | Menor | En PUT concurrentes, el correo de evento muestra diffs `30002 -> 30004` (lectura-modificación-escritura sin bloqueo): el rastro de "valor anterior" puede no ser fiel. El valor final y `audit_logs` son correctos. Además, la pantalla envía los 7 campos en cada guardado (puede pisar un cambio hecho por otra vía entre la carga y el guardado). | 5 PUT simultáneos |
| m4 | Menor (cosmético) | Los miles se muestran con coma al estilo inglés (`3,502`, `+79,953`) porque se usa `toLocaleString()` sin locale, mientras las fechas usan `es-PA`. Los mensajes de validación del servidor muestran el nombre técnico del campo ("max productos con delta pct"), aunque la pantalla no los muestra por M1. | Panel, tabla de corridas |

Observaciones (no son fallo): el interruptor y los umbrales editados desde la pantalla no tienen botón para "revertir"; el panel no muestra el cálculo del umbral tras una corrida retenida (solo el texto del circuit breaker).

## Lo que sí funciona
Línea base completa y consistente (11.707/11.707), cero deriva Σ bodegas vs `stock_quantity`, kardex 100 % tipo Ajuste con referencia a la corrida y `saldo_inicial + cantidad = saldo_resultante`, idempotencia de la segunda corrida, memoria (64 MB) y 12 s en dry-run, gating 403/401, validaciones 422 con topes, auditoría y avisos por cambio de ajustes, panel responsivo, modo oscuro, sin emojis.

## Pendientes / aclaraciones
- No probados en dev real por regla (mutarían datos): criterios 2, 3, 5 y la reversa del 12; están cubiertos por tests de la suite que no se corrió aquí.
- No se creó ningún marcador de gate ni se transicionó/comentó Jira. Quien despacha decide M1 (recomendado) y que m1-m4 vayan a seguimiento.
- Specs `_preqa_scrum1114*.spec.ts` quedan sin commitear en `atlanticerp-frontend/e2e/` (mismo patrón de los `_preqa_*` existentes).
