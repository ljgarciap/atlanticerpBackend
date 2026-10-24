# Diseño (Arquitecto) — Staging en crudo de almacenes, stock, traspasos y kardex de ICG

- **Estado:** APROBADO por Luis 2026-10-13 (incluye aceptar el crecimiento de la BD; costo AWS a validar antes de prod).
  Limpieza posterior de datos permitida si se requiere: ver sección 10. Spec: `docs/specs/icg-staging-almacenes-stock-traspasos.md`.
- **Fecha:** 2026-10-13. **Actualizado 2026-10-15:** ICG ya no se usa (Luis): sus datos quedan congelados. Se descarta F3
  (scheduler/alerta de salud) y `movimientos` pasa a ser una **carga única reanudable**, sin incremental (ver sección 11).
- **Decisiones ya tomadas por Luis:** `MOVIMENTS` completo; foto diaria de `STOCKS`; dev → test → prod.

## 1. Principio
Copia fiel de ICG en `legacy.*`, sin interpretación. Cero acoplamiento con `bodega.*`. Lo que se decida sobre
qué almacenes refleja AtlanticERP se resuelve después con `legacy.almacen_equivalencias` y no obliga a re-extraer.

## 2. Tablas nuevas (schema `legacy` del tenant, prefijo `icg_` en el nombre)
Mismo estilo que `legacy.productos` (snake_case, `created_at/updated_at`). Todas las llaves naturales de ICG
son únicas; nada de FK hacia `bodega.*` ni `ventas_diseno.*`.

| Tabla | Llave única | Carga |
|---|---|---|
| `legacy.almacenes` | `codalmacen` | foto completa (11 filas) |
| `legacy.stock_almacen` | `codarticulo, talla, color, codalmacen` | foto completa diaria (181 727 filas) |
| `legacy.costo_almacen` | misma | foto completa diaria (11 031 filas) |
| `legacy.traspasos_cab` | `serie, caja, numero` | foto completa diaria (7 253, incluye anulados) |
| `legacy.inventarios_cab` | `fecha, codalmacen` | foto completa diaria (215) |
| `legacy.movimientos` | `icg_id` (= `MOVIMENTS.ID`) | incremental por `icg_id` mayor al ya cargado |
| `legacy.almacen_equivalencias` | `codalmacen_icg` | manual; `warehouse_id` nullable, `estado` propuesto/confirmado |

- Columnas: las de ICG sin las binarias (`VERSION`); fechas y decimales con el mismo tipo que ICG. `NOTAS`
  (ntext) de `ALMACEN` sí se copia como `text`. `stock_almacen` agrega `stock_corregido` calculado al cargar
  (`max(stock, 0)`), que reemplaza a `STOCKSNEGATIVOSCOMO0`.
- `legacy.movimientos`: índices por `(codarticulo, fecha)`, `(seriedoc, numdoc)` y `(tipo)`. `tipo` se conserva
  tal cual (`REG`, `TMP`, `ENV`, `MOD`); el análisis excluye `TMP` por filtro, no al copiar.
- Reconciliación de huérfanos: `stock_almacen.codalmacen` sin fila en `almacenes` (caso A1) se carga y el
  comando de verificación lo lista; no hay FK que lo impida.
- Migraciones prefijadas por módulo (`legacy`), cada una revisada por el Arquitecto antes de correr.

## 3. Extracción (patrón existente, sin inventar mecanismo)
- Un extractor por tabla en `App\Modules\Legacy\Extractors` con contrato en `Contracts`, consulta/mapeo
  `static` y puros, y solo el método de extracción toca la conexión `legacy_icg` (`illumiread`, solo SELECT).
- **Memoria:** el host dev/test/prod es t3.small de 2 GB con ~1,1 GB usados y historial de OOM. Por eso
  `stock_almacen` y `movimientos` se leen **paginados** (`ORDER BY` + `OFFSET…FETCH`, páginas de ~5 000) y se
  cargan por upsert por página antes de pedir la siguiente (igual que `AlbaranStagingLoader::loadPaginated`).
  Nada de `DB::select()` de la tabla completa.
- `movimientos` incremental: lee `WHERE ID > :max_cargado ORDER BY ID`. La primera corrida (1,38 M filas) es una
  carga inicial en un proceso aparte (`--inicial`), de noche, reanudable desde el último `icg_id`.
- Cada corrida registra una fila en `legacy.sync_runs` (entidades nuevas: `almacenes`, `stock_almacen`,
  `costo_almacen`, `traspasos`, `inventarios`, `movimientos`).

## 4. Comandos y programación
> **Actualización 2026-10-15 — reemplaza lo de abajo:** ICG está congelado, no hay cadencia diaria ni scheduler. Los
> horarios y la alerta de salud descritos aquí quedan **descartados** (F3 abandonada). Los comandos se corren a mano,
> una vez por entorno, desacoplados del terminal (nohup) y verificados con `legacy:verify-almacenes`.
- `legacy:sync-almacenes {--tenant=} {--entidad=} {--inicial} {--dry-run}` por entidad, y el paso de verificación
  `legacy:verify-almacenes` (conteos y suma de `STOCK` por almacén ICG vs staging; lista huérfanos).
- **Diario**, como proceso de SO separado (patrón SCRUM-918), **no** dentro de `legacy:sync-incremental-all`:
  - esa cadena es semanal en dev/test (~75 min) y diaria a las 21:00 en prod; sumar `movimientos` completo
    alargaría una cadena que ya duele y mezclaría una cadencia diaria con otra semanal.
  - horarios nuevos, separados entre entornos para que no lean ICG a la vez: **prod 22:30, test 02:30, dev 04:00**
    (dev queda antes de los jobs de 03:00–03:45 si se mueve a 02:00; confirmar contra la cadena de dev del domingo
    05:00 y `security:analyze`). `withoutOverlapping()` y alerta SNS si pasan 2+ días sin corrida exitosa
    (reutiliza `legacy:check-sync-health`).
- La foto diaria de `STOCKS` mueve ~180 000 filas de lectura: carga baja para ICG, pero se mide en la primera
  corrida y se avisa a Luis si pasa de 10 min.

## 5. Capacidad (medida 2026-10-13, dev)
- Postgres dev: 936 MB usados de 20 GB (`/data/postgres`), 18 GB libres. `movimientos` estimado en
  400–700 MB con índices; el resto, < 150 MB. Entra sin problema, pero **duplica o más** el tamaño de la BD y por
  tanto los `pg_dump` a S3 y los snapshots EBS de prod: validar costo con AWS Architect antes de prod (los precios de AWS
  del diseño de la gemela tampoco están validados).
- Raíz del host: 7,1 GB libres; no se usa disco local para la carga (streaming por páginas).

## 6. Seguridad y operación
- Solo lectura contra ICG; sin escrituras ni locks (`WITH (NOLOCK)` evaluado: no se usa, se prefiere
  lectura paginada por llave).
- Los datos nuevos no se exponen en API ni en UI en esta fase (staging interno). Si luego se muestran,
  pasan por permiso propio y revisión de Cybersecurity.
- Auditoría: cada corrida deja `sync_runs`; el comando de verificación no escribe.
- Rollback: tablas nuevas e independientes; revertir = `migrate:rollback` de las migraciones del batch, sin tocar
  datos existentes.

## 7. Plan por fases (para el PM)
1. **F1** — migraciones + extractores/loaders de `almacenes`, `stock_almacen`, `costo_almacen`,
   `traspasos_cab`, `inventarios_cab` + `legacy:verify-almacenes` + tests (fixtures sin red).
2. **F2** — `movimientos` (incremental + `--inicial` reanudable) + tests de corte a mitad de carga.
3. **F3** — programación diaria, alerta de salud, runbook en `docs/runbooks/`.
4. **F4** — corrida real en dev, verificación contra ICG, luego test y prod por el flujo normal.
Cada fase pasa por Senior Review; Pre-QA aplica en F4. Sin cambios en `catalog_products` ni en `bodega.*`.

## 8. Fuera de este diseño
Mapeo definitivo de almacenes y qué bodegas muestra AtlanticERP (esperan respuesta del negocio); gemela de ICG y `.bak`
final; las ~90 tablas restantes con columnas de almacén.

## 10. Limpieza posterior (acordado 2026-10-13)
Cargar completo no obliga a conservarlo: son tablas de staging independientes, se pueden depurar después.
- Candidatos: `movimientos` con `tipo='TMP'` (462 k filas), y cualquier tipo/rango que el análisis descarte.
- Una limpieza es un cambio destructivo en BD: requiere confirmación explícita de Luis, con conteo previo y,
  en prod, backup verificado antes (regla del proyecto).
- `DELETE` no devuelve espacio al disco: para recuperarlo hace falta `VACUUM FULL` o, mejor, `TRUNCATE` de
  una tabla completa; `movimientos` se puede particionar por `tipo` si se quiere borrar barato (decidir en F2).
- La carga incremental usa `icg_id > máximo cargado`: borrar filas **viejas** no se vuelve a cargar; borrar las
  **más recientes** sí. Una recarga `--inicial` también las restauraría: el comando registra los `tipo` excluidos
  en una configuración (no hardcodeada) para respetarlos.
- Mientras ICG esté vivo, lo borrado se puede volver a extraer. **Tras el apagado, la fuente de verdad es el
  `.bak` final**: no se depura nada irreversible hasta tenerlo guardado y probado.

## 11. Actualización 2026-10-15 — ICG congelado y validación contra Excel
- **F1 y F4 en dev cerradas:** corrida real verificada contra ICG (conteos y suma de STOCK iguales en los 12 códigos;
  6 filas de stock con `CODALMACEN` vacío, stock 0, cargadas como huérfanas).
- **F2 (`movimientos`) simplificada:** carga única de 1 384 677 filas (`MOVIMENTS`, PK `ID`, 32 columnas sin binarias,
  `ID` no contiguo hasta 1 830 964), paginada por `ID`, reanudable desde el último `icg_id` cargado, sin lectura incremental
  futura. Se conserva `tipo` tal cual (el filtro de `TMP` es de análisis, no de carga; ver sección 10).
- **F3 abandonada:** sin scheduler, sin `withoutOverlapping`, sin alerta de salud ni runbook de operación diaria. Queda
  solo un runbook corto de la carga única por entorno.
- **Validación con inventario real (Excel de Luis, 13-oct-2026, `Inventario SHOWROOM, EXTERNO Y SANTAMARIA`):** columnas
  AL2 «SHOWROOM OBARRIO», B1 «SHOWROOM CLIENTE», B2 «SHOWROOM SM». El Excel (números en formato es: `5.474,8`) coincide con
  el staging de ICG por referencia (`legacy.productos.ref_proveedor`): B1 93/93 y B2 107/107 referencias idénticas; AL2
  2 071 de 2 072 (`730515A`: ICG 1 u, Excel 0), más 27 u en 9 filas de productos fuera de `legacy.productos` y una línea
  de servicio con −1 solo en el Excel. Unidades: AL2 5 474,8 / B1 660 / B2 683. Valoración del Excel: AL2 257.230,66,
  B1 22.324,00, B2 62.756,75. Confirma AL2/B1/B2 como bodegas de showroom en el mapeo.

### Decisión de negocio 2026-10-15 (Luis): el stock de ICG se asume real
Las diferencias entre el Excel y el staging de ICG (AL2) se explican así: 8 artículos **descatalogados** con stock (28 u:
2332 KS-001-1.5M-WH 3, 2333 KS-001-1M-BK 1, 55818 ALU-PROFILE-2 −1, 58358 06050-1001 1, 59792 PHI-LEDR-6WGU10 15,
64239 ALUPROFILE-3 7, 65183 YSD-24300-DTL 1, 67083 IL-DEC-1036SV 1) que `legacy.productos` excluye por su filtro
`DESCATALOGADO='F'` y que el Excel no lista; la línea de servicio 68602 (−1, sin referencia, activa); y `730515A`
(ICG 1 u en AL2, Excel 0; último movimiento 2022). ICG está congelado: su último movimiento es del 2026-10-09.
**Criterio:** se asume que las unidades existen y se ajustan cuando se haga el inventario físico. Consecuencia para el
paso staging → AtlanticERP (fuera de SCRUM-1136): el mapeo de stock a `bodega.*` debe incluir los artículos descatalogados con
stock distinto de cero (no limitarse a `legacy.productos`) y tomar el valor de ICG (p. ej. `730515A` = 1). Pendiente de
decidir: la línea de servicio 68602 (−1) no es un bien físico; propuesta por defecto, excluirla del stock.

### F5 (aprobada por Luis 2026-10-15): artículos descatalogados con stock
Spec: `docs/specs/icg-descatalogados-con-stock.md`. El sync de productos de ICG está apagado y `legacy.productos` solo tiene
activos, así que los 95 descatalogados con stock distinto de cero (65 con stock positivo) no llegarían a AtlanticERP. Enfoque:
(1) comando único e idempotente que los stagea en `legacy.productos/producto_precios/producto_costos` con `descatalogado=true`,
sin tocar el flujo de activos; (2) los 10 que comparten `REFPROVEEDOR` con un activo se vinculan a él (stock se suma sobre un
solo producto, sin duplicar `reference`); (3) los 84 restantes se crean en `catalog_products` como **inactivos** con un comando
dry-run por defecto (`--apply --operador=`); (4) el 1 sin referencia se lista para revisión manual. No escribe stock en
`bodega.*` (eso es la fase de mapeo). Orden de despliegue: antes de la corrida real del mapeo de stock.

## 9. Decisiones que necesito de Luis
1. Aprobar este diseño (tablas, cadencia diaria como comando aparte, horarios propuestos).
2. Aceptar que la BD duplica o más su tamaño (impacto en backups y snapshots) y que se valida el costo con AWS Architect antes de prod.
