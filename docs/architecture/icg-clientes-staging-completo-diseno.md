# Diseño — Staging completo de CLIENTES de ICG y corrección de RUC/DV

Estado: **APROBADO por Luis 2026-10-13** (con las recomendaciones de §10: excluir `CVC`/`PASS`/`PASSWORDCOMMERCE`/`NUMTARJETA`, no copiar `FOTOCLIENTE`, Paso 0 y medición autorizados y ya ejecutados). Siguiente paso: PM desglosa en tareas. Spec aprobada: `docs/specs/icg-clientes-staging-completo.md` (repo exterior `atlanticerp`, decisiones D1–D10).
Nada de esto está implementado. Lo marcado **[verificar]** no se confirmó contra el código ni contra datos reales.

> **CAMBIO DE ALCANCE (Luis, 2026-10-13): se elimina la consulta a la DGI.** La extracción solo trae el dato del campo correcto (`NIF20`), lo normaliza en RUC y DV, y actualiza la base de datos. La fase F4 de este documento, el nombre oficial de la DGI y la tabla `sub_client_tax_verifications` quedan **anulados**. El DV que no venga dentro de `NIF20` queda vacío.

## 1. Resumen

Tres fases independientes (la cuarta fue anulada), cada una con su propio gate. El orden importa: F1 no cambia comportamiento de AtlanticERP; F2 y F3 sí.

| Fase | Qué hace | Toca datos vivos de AtlanticERP |
|---|---|---|
| F1 | Copia cruda de `CLIENTES` y `CONTACTOSCLIENTES` en `legacy.*` | No |
| F2 | Normaliza `NIF20` a RUC + DV; el Matcher y la fusión usan el RUC completo | Solo clientes nuevos o por promover |
| F3 | Corrige `sub_clients.tax_id` truncado ya promovidos | Sí, con dry-run, auditoría y reversa |
| ~~F4~~ | ~~Completa/verifica DV y nombre oficial contra la DGI~~ — **anulada** | — |

## 2. Respuestas a las preguntas abiertas de la spec

**P1 — ¿Se protege de la corrección masiva un `tax_id` editado a mano?** Sí, y sin consultar el historial de auditoría. La corrección se aplica **solo si se cumplen las tres precondiciones**: (a) el `tax_id` actual normalizado es igual al `CIF` normalizado de ICG, (b) el `NIF20` normalizado empieza con ese valor, (c) el `NIF20` es más largo. Si alguien lo editó a mano, (a) deja de cumplirse y el cliente se omite y se reporta. Es idempotente y no depende de detectar quién editó qué.

**P2 — ¿Tabla ancha nueva o ampliar `legacy.clientes`?** **Tabla nueva**: `legacy.clientes_raw` y `legacy.contactos_cliente_raw`. `legacy.clientes` está acoplada al Matcher, a la fusión y al hash incremental con 15 columnas tipadas; ensancharla a ~120 mezcla "copia de archivo" con "dato de trabajo". En `legacy.clientes` solo se agregan las columnas derivadas de F2.

**P3 (anulada) — ¿Dónde va el nombre oficial de la DGI?** En una **tabla de verificación aparte** (`ventas_diseno.sub_client_tax_verifications`), no como columna de `sub_clients`: guarda historial de consultas, no engorda la tabla viva y no toca `fillable`. El **DV oficial sí** va a `sub_clients.tax_id_dv` (columna existente).

## 3. F1 — Copia cruda

- Tablas `legacy.clientes_raw` (llave `codcliente`, único) y `legacy.contactos_cliente_raw` (llave `id`, único). Columnas con los mismos nombres de ICG en minúscula, tipos mapeados desde el esquema real.
- **Paso 0 obligatorio (Backend/DevOps):** volcar `INFORMATION_SCHEMA.COLUMNS` de `CLIENTES` y `CONTACTOSCLIENTES` (solo lectura) y generar migración y mapper desde ese volcado, no a mano. Así no se repite el error de asumir tipos (`BLOQUEADO`, SCRUM-755).
- Extractores: heredan de `IcgTablaExtractor` (SCRUM-1136), paginado por `CODCLIENTE`, columnas descubiertas en la corrida. `VERSION` y binarios quedan excluidos, como ya hace la base. **`FOTOCLIENTE` (binaria) no se copia** salvo decisión contraria.
- Loader: mismo patrón que `AlmacenStagingLoader` (upsert por página en su propia transacción, ≤30.000 parámetros, ≤500 filas por INSERT). Se agrega `source_hash` (`RowHasher`) para no reescribir filas sin cambios.
- Comandos: `legacy:sync-clientes-raw {--tenant} {--dry-run}` y `legacy:verify-clientes-raw` (solo lectura, cuenta filas y compara contra ICG). Registro de corrida en `legacy.sync_runs` (entidad `clientes_raw`).
- Sin scheduler en F1 (mismo criterio que F1 de SCRUM-1136). Se evalúa sumarlo a `legacy:sync-incremental-all` después.
- Upsert-only: una fila borrada en ICG no se borra en staging. `verify` la reporta.

### Decisión de seguridad que se aparta de D1 (requiere Luis y Cybersecurity)
`CLIENTES` tiene columnas de **tarjeta, credenciales y banco**: `NUMTARJETA`, `CVC`, `TARCADUCIDAD`, `TIPOTARJETA`, `PASS`, `PASSWORDCOMMERCE`, `USUARIO`, `NUMCUENTA`, `CODIGOIBAN`, `CODSWIFT`. En el cliente 6889 están vacías o NULL, pero **no sé cuántos clientes las tienen llenas**. Guardar número de tarjeta, CVC o contraseñas en texto plano en staging es un riesgo (PCI/credenciales) que D1 ("copia cruda completa") no contempló.
Recomendación: (1) medir en ICG, solo lectura, cuántas filas tienen estas columnas con dato; (2) **excluir por defecto `CVC`, `PASS`, `PASSWORDCOMMERCE`, `NUMTARJETA`** de la copia; (3) las bancarias (`NUMCUENTA`, `CODIGOIBAN`, etc.) se copian si Cybersecurity lo aprueba, con acceso solo `superadmin.all`. Si ICG igual se respalda completo en la instancia gemela, esos datos no se pierden.

### Resultado de Paso 0 y de la medición (2026-10-13, ICG solo lectura vía dev)
- `CLIENTES`: 120 columnas, 7.796 filas, llave primaria `CODCLIENTE`. Tipos: 64 `nvarchar`, 29 `int`, 7 `bit`, 5 `datetime`, 5 `float`, 3 `smallint`, 3 `nchar`, 1 `ntext`, 1 `image` (`FOTOCLIENTE`), 1 `timestamp` (`VERSION`). El esquema real tiene 119 columnas (el 120 era un error de conteo). Se copian **110**: se excluyen `image`/`timestamp` (`FOTOCLIENTE`, `VERSION`) y, por lista fija de seguridad, `CVC`, `PASS`, `PASSWORDCOMMERCE`, `NUMTARJETA`, `USUARIO`, `TIPOTARJETA` y `TARCADUCIDAD` (Senior Review, 2026-10-13).
- `CONTACTOSCLIENTES`: 10 columnas, 821 filas, **sin llave primaria declarada**; `ID` es nullable en el esquema pero en los datos es único y sin nulos (821 distintos, 0 nulos, 0 huérfanos). Sirve como llave natural, con un `RuntimeException` si aparece un `ID` nulo. Tiene 3 columnas que hoy no se leen: `DPTOEDI`, `FACTURACION` y `TESORERIA`.
- Columnas sensibles con dato: `NUMTARJETA`, `CVC`, `TARCADUCIDAD`, `TIPOTARJETA`, `PASS`, `PASSWORDCOMMERCE`, `USUARIO`, `CODBANCO`, `NUMSUCURSAL`, `DIGCONTROLBANCO` y `DIRECCIONBANCO` tienen **0 filas**. `NUMCUENTA` tiene 1, `CODIGOIBAN` 3, `CODSWIFT` 4 y `NOMBREBANCO` 3. `FOTOCLIENTE` tiene dato en 8 clientes.
- Conclusión: el riesgo de tarjeta/credenciales es prácticamente nulo hoy. Se mantiene la exclusión por defecto de `CVC`, `PASS`, `PASSWORDCOMMERCE` y `NUMTARJETA` (costo cero, evita que un dato futuro llegue a staging) y las columnas bancarias (≤4 filas) se copian con acceso restringido a `superadmin.all`. `FOTOCLIENTE` no se copia.

## 4. F2 — Normalización de NIF20

- `legacy.clientes` gana: `nif20` (string 20), `ruc` (string, normalizado), `ruc_dv` (string 4, nullable), `ruc_origen` (`nif20` | `cif` | `ninguno`), `ruc_estado` (`ok` | `truncado_posible` | `revisar`).
- El extractor de `legacy.clientes` agrega `NIF20` al `SELECT` y al hash.
- Clase pura `TaxIdNormalizer` (sin DB, testeable) con reglas: (1) `NIF20` manda sobre `CIF`; (2) quitar espacios; (3) separar `DV` con patrón `^(.+?)\s*DV\s*(\d{1,4})$`; (4) lo que no cumple un patrón reconocido → `ruc_estado = revisar`, sin modificar; (5) `CIF` de 12 caracteres sin `NIF20` → `truncado_posible`.
- Fixtures de prueba con los valores reales medidos: `155716554-2-2021`, `155783782-2-2026DV35`, `987695-1-533551 DV09`, `1671600-1-679822`, `6-712-881`, `RUC 1671600-`, `155662320 2 `.
- `ClienteMatcher`: compara primero por `ruc` (completo); si no hay, por `CIF` normalizado como hoy; luego email. **Cambia el resultado del matching**, así que F2 incluye un dry-run que reporta cuántos clientes cambian de `match_status` antes de activar el cambio.
- `ClienteFusionLoader`: al promover usa `ruc` y `ruc_dv`; cédulas dejan `tax_id_dv` vacío (D8).

## 5. F3 — Corrección de los sub_clients ya promovidos

- Comando `legacy:fix-clientes-ruc {--tenant} {--dry-run | --apply --operador=} {--revert=<run_id>}`. `--dry-run` por defecto, mismo estilo que `legacy:backfill-stock-bodega-central`.
- Candidatos: `legacy.clientes` con `sub_client_id` no nulo y las tres precondiciones de P1. Los casos de `ruc_estado = revisar` (7340, 7759, 5620 y similares) **no se tocan** (D9) y salen en el CSV.
- Aplicación con Eloquent (`SubClient::save()`), no `UPDATE` masivo: así se dispara `LogsAuditTrail` **[verificar]** y queda el valor anterior en `audit_logs`.
- Tabla `legacy.cliente_ruc_correcciones` (run_id, sub_client_id, `tax_id` anterior/nuevo, `tax_id_dv` anterior/nuevo, `aplicada_at`) para reversa por corrida, sin depender de reconstruirla desde `audit_logs`.
- Verificar antes de aplicar en prod **[verificar]**: que facturas, cotizaciones y la facturación electrónica ya emitidas no lean `sub_clients.tax_id` en vivo (de ser así, corregirlo cambia documentos históricos). Si guardan snapshot, no hay efecto.

## 6. F4 — DGI

**Anulada** por decisión de Luis (2026-10-13). Queda como posible trabajo futuro, fuera de este ticket.

## 7. Orden de despliegue (D10)

dev → test → prod, cada paso con su dry-run y reporte CSV revisado. Prod solo con aprobación expresa de Luis, y nunca F3/F4 en prod sin que el CSV de dev y test haya sido revisado. F1 puede ir a los tres entornos antes que F2–F4.

## 8. Gates de proceso

Cada fase pasa por: Senior Review → Pre-QA (criterios negativos: RUC fuera de patrón, DGI caída, cliente editado a mano, reversa) → QA. Cybersecurity revisa F1 (datos sensibles) antes de ejecutar. Todo fix se pushea a `dev`. Tickets en Jira: los crea Luis o los pide explícitamente; no se crean solos.

## 9. Riesgos

- **Datos de tarjeta/credenciales en staging** (§3) — el más serio; medir antes de copiar.
- **Cambio de matching por RUC completo** — medir el efecto en dry-run (F2).
- **Prod con facturación electrónica activa** — un `tax_id` mal corregido produce rechazos del PAC; por eso las precondiciones estrictas y la reversa por corrida.
- **Memoria del host** (t3.small, historial de OOM): paginación obligatoria, un proceso por entidad, sin chocar con el sync nocturno.
- **Esquema real de ICG sin volcar todavía**: toda la F1 depende del Paso 0.

## 10. Preguntas para Luis antes de pasar al PM

1. ¿Excluimos de la copia cruda `CVC`, `PASS`, `PASSWORDCOMMERCE` y `NUMTARJETA` (apartándonos de D1 solo en esas 4 columnas)?
2. ¿Autoriza medir, en solo lectura contra ICG, cuántos clientes tienen datos en las columnas de tarjeta, credenciales y banco?
3. ¿Copiamos también `FOTOCLIENTE` (binaria)? Mi recomendación es no.
4. ¿Autoriza el Paso 0 (volcar el esquema de `CLIENTES` y `CONTACTOSCLIENTES` desde ICG)?
