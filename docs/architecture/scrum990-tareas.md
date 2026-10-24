# SCRUM-990 — Desglose del PM (aislar productos de Lafayette)

Insumos aprobados por Luis el 2026-09-30: spec `docs/specs/scrum990-aislar-lafayette.md`, diseño del Arquitecto
`docs/architecture/scrum990-aislar-lafayette-diseno.md` (secciones 1-11) y spec visual de UX/UI
`docs/specs/scrum990-zona-libre-ux.md` (en elaboracion).

## Decisiones de Luis que modifican o cierran el diseno
- Tabla propia `bodega.lafayette_productos`; alta en AtlanticERP manual sin precarga; permisos existentes de crear productos.
- Ventas & Diseno, Sustitutos y Ordenes de compra ya no ven productos de Lafayette (solo los creados a mano en AtlanticERP).
- Precio en Zona Libre: solo `precio_5`, etiquetado como precio de Lafayette.
- Producto que desaparece de la fuente: se marca `fuera_de_fuente_at` y se oculta (no se borra).
- `warehouses.solo_consulta` reemplaza a `mostrar_sin_stock`, mas trigger de defensa contra escrituras de stock.
- Historial de prueba contra la bodega Zona Libre (movimientos, recepciones, devolucion, conteo): **se anula** dentro de la limpieza.
- Orden: **pipeline nuevo primero, limpieza despues**, entorno por entorno (dev, test, prod). Cada entorno con diagnostico previo,
  respaldo y confirmacion explicita de Luis. `lafayette:aislar-catalogo` no se ejecuta en ningun entorno sin esa confirmacion.
- Test y prod: diagnostico de solo lectura ya hecho (ver abajo). Nada escrito todavia.

## Diagnostico de solo lectura ya ejecutado (2026-09-30)
| | dev | test | prod |
|---|---|---|---|
| Unidades en Zona Libre / Bodega Central | 1.767.865 / 85.402 | 1.767.796 / 4.518 | 2.141.669 / **69** |
| Productos de origen Lafayette | 25.816 | 25.816 | 25.824 |
| ICG enlazados (legacy.productos) | 8.001 | 8.001 | 8.015 |
| Corridas del sincronizador | 9 | 2 | 5 (12:00 y 22:00 a diario) |
| Referencias a productos de origen Lafayette | 4 prod. / 7 lineas cotiz. | 10 pedido, 19 cotiz., 4 insumo | 48 cotiz., 8 pedido, 9 ajuste, 2 mov. |
| Referencias a productos PUROS (no ICG) | 0 | no medido | **0** |

Implicacion: en prod las 67 referencias son a productos ICG reales (28 en cotizaciones, 3 en pedidos, 6 en ajustes, 2 en movimientos), que el
sincronizador lleva desde el 2026-09-27 sobrescribiendo. **La restauracion exacta desde `audit_logs` es obligatoria en prod** y se verifica
producto por producto sobre esos 39. Los puros se pueden borrar sin tocar ningun documento.
Pendiente de medir en test: referencias a puros, movimientos contra la bodega Zona Libre; y en prod: movimientos/recepciones contra la bodega Zona Libre.

## Tareas
| ID | Rol | Tarea | Depende de |
|---|---|---|---|
| B1 | Backend | Migraciones de tenant: `lafayette_productos` (+ `search_text`, indices trigram), `warehouses.solo_consulta` (backfill Zona Libre), columnas nuevas de `lafayette_sync_runs` | - |
| B2 | Backend | Modelo + Mapper + Loader + Command reescritos (upsert por `lafayette_id`, escribe solo lo que cambio, marca `fuera_de_fuente_at`, guarda de extraccion vacia); eliminar loader viejo; tests de pipeline y de contrato | B1 |
| B3 | Backend | Guards: `ProductWarehouseStock::saving`, `Warehouse::operativas()` en los consumidores, validaciones en recepcion, conteo, ajuste, devolucion, alta con stock inicial y reubicacion; trigger de defensa | B1 |
| B4 | Backend | `GET /bodega/zona-libre/productos` (solo lectura, paginado, busqueda), ajustes de `show()`/`index()` de bodegas; tests de endpoint, de rutas (sin escritura) y de rendimiento con 25.8K | B1, B2 |
| B5 | Backend | Comando `lafayette:aislar-catalogo` (dry-run / apply / rollback): respaldo en tablas `*_bak_scrum990`, restauracion de ICG desde `audit_logs`, borrado de puros y de stock de Zona Libre, **anulacion del historial de prueba contra la bodega**, verificaciones con rollback | B1, B2 |
| F1 | Frontend | (tras spec UX/UI y contrato de B4) tipos, API, hook, tabla de Zona Libre, rama en `BodegasPage`, ocultar Zona Libre en selectores, i18n es/en, vitest | B4 |
| F2 | Frontend | Playwright permanente `e2e/bodega-zona-libre.spec.ts` | F1 |
| D1 | DevOps/datos | Runbook de respaldo (pg_dump + copia fuera del servidor), orden de despliegue y verificaciones por entorno; agendado del sincronizador | B2 |
| D2 | DevOps/datos | Diagnostico de solo lectura: **hecho** para dev, test y prod; completar lo pendiente justo antes de ejecutar en cada entorno | - |
| D3 | DevOps/datos | Ejecucion por entorno (dev, luego test y prod, cada uno con confirmacion de Luis) | B1-B5, F1, D1 |
| T1 | Tech Writer | ADR del aislamiento, `CLAUDE.md` (seccion Lafayette) y manual de Bodega | en paralelo |
| E | Backend | Release E: quitar `external_*`, `mostrar_sin_stock` y tablas respaldo; retirar el comando | D3 en los 3 entornos |

## Orden de trabajo
1. **Ahora, en paralelo:** B1 -> (B2, B3) -> B4 -> B5 en el backend, en un solo hilo con commits por tarea (mismo repo, dependencias entre ellas).
   Frontend arranca F1 cuando UX/UI entregue la spec y B4 fije el contrato.
2. **Gates:** Senior Review + Cybersecurity en paralelo (superficie nueva: un endpoint de lectura y un comando destructivo de una sola vez con
   credenciales de migracion); Visual Reviewer + Pre-QA fusionados con la spec visual como referencia; luego QA.
3. **Gate real antes de cualquier push:** `infra/test.sh` completo + PHPStan nivel 8 + `npx vitest run` + Playwright del modulo.
   Nunca `npm run test`. Sin push sin aprobacion de Luis.
4. **Por entorno:** diagnostico -> respaldo -> deploy del pipeline nuevo -> sync para poblar la tabla -> `--dry-run` (Luis revisa) -> `--apply`
   (confirmacion explicita) -> sync incremental de ICG -> verificacion funcional.

## Lecciones aplicadas de SCRUM-984
- Toda llamada a un servicio externo (aqui, la base MariaDB de Lafayette) necesita ademas de los fakes una prueba contra el servicio real en dev.
- Commits con rutas explicitas (`git commit -- <rutas>`) y `git show --stat` despues: otro proceso comparte el checkout.
- Revisar con `ps -eo command` (no con un `grep` dentro del mismo comando) que no haya otro `test.sh` corriendo.
- Migraciones de tenant solo en `database/migrations/tenant/`.

## Estado de la implementación backend (B1-B5) — 2026-09-30
Commits (rama `dev`): B1 `51fc22c4`, B2 `9b63f5ed`, B3 `d6cbd546`, B4 `15534f8b` + `113de3fe` (tipos PHPStan y medición de memoria), B5 `4cd8c785`.
B1-B3 ya fueron publicados en `origin/dev` por un `push HEAD` de otra sesión (equipo de Clientes) desde el checkout compartido; B4 y B5 siguen locales.

Desviaciones respecto del diseño aprobado (todas menores, ninguna cambia una decisión de Luis):
1. **Rutas de escritura bajo `/bodega/zona-libre`:** el diseño pedía un test de "sin escritura bajo `/bodega/zona-libre`", pero ese prefijo YA tiene rutas de escritura de
   "Solicitudes Zona Libre" (SCRUM-440: POST/PUT `/requests`). El test se acota a `/bodega/zona-libre/productos*` (solo GET/HEAD).
2. **`Warehouse::operativaRule()` es un closure**, no `Rule::exists()`: con una tabla calificada por schema Laravel la interpreta como "conexión.tabla".
3. **Contrato de `GET /bodega/zona-libre/productos`** (fijado por el coordinador, no por el diseño): parámetros `search` (mín. 2), `categoria`, `solo_disponibles`
   (acepta `true|false|1|0`: un cliente JS serializa `"true"`, que la regla `boolean` de Laravel rechaza), `sort`/`dir` (lista blanca) y `per_page` 25|50|100; un valor fuera de contrato responde 422.
   Cada ítem: `id` y `referencia_lafayette` (ambos = `lafayette_id`), `referencia` (la `referencia` de Lafayette), `descripcion`, `marca`, `categoria`, `url_imagen`, `disponible`,
   `precio` (= `precio_5`, sin etiqueta), `synced_at` (= `fuente_actualizado_at` de la fuente, ISO 8601). `meta`: `total`, `per_page`, `current_page`, `last_page`, `categorias`, `total_catalogo`, `ultima_sincronizacion`.
4. **Historial anulado contra Zona Libre:** solo en una lista explícita de tablas (`inventory_movements`, `purchase_order_receipt_lines`, `customer_returns`, `general_count_requests`);
   cualquier OTRA tabla con filas contra esa bodega (ubicaciones, mapeos, líneas de ajuste...) NO se toca y bloquea la limpieza. Borrar líneas de recepción de compra puede dejar
   estados de órdenes de compra desfasados y no revierte `stock_quantity`: es dato de prueba por decisión de Luis, pero hay que mirar el dry-run de cada entorno.
5. **Verificación de la restauración sobre TODOS los ICG**, no una muestra de 200 (más estricto que el diseño); se descartó la clave `aislar_muestra_verificacion`.
6. **`lafayette_productos` debe estar repoblada** (>= 90 % de los productos de origen Lafayette) antes de aplicar; si no, el comando se niega (la limpieza perdería el dato).
7. **Fixture de 20 filas:** son filas REPRESENTATIVAS armadas a mano con la forma real de `vista_productos_lighting`; no se capturaron en vivo (el desarrollo no consulta la MariaDB de Lafayette).

## Segunda ronda (Senior Review + Seguridad) — 2026-10-01
Commits (rama `dev`, locales, sin push): Bloque A `85261e5d`, B `6bfbaed0`, C `375091b3`, D `ba88b726`. Informes de origen: `docs/reviews/scrum990-senior-review.md` y
`docs/security/scrum990-revision-seguridad.md`.

Decisiones y desviaciones:
8. **Guardas (ALTO-1):** filas residuales de `product_warehouse_stock` contra bodegas `solo_consulta` se filtran en los consumidores (`scopeEnBodegaOperativa`); el conjunto de
   bodegas solo_consulta se cachea en proceso con TTL (`lafayette.cache_bodegas_solo_consulta_segundos`) y se invalida desde `Warehouse::booted()`.
9. **Sincronizador:** cuarentena por fila (mapper y BD con savepoint); el contador `errores` de la corrida cuenta las filas rechazadas y la corrida queda `partial` si superan
   `filas_rechazadas_max_ratio` (1 %). La línea base de la guarda es solo la última corrida `success`. El umbral de la guarda vive en `auth.system_settings`
   (`lafayette_guarda_min_ratio`, sembrado en 0,5, acotado a 0..1, fallback a config). **No hay endpoint ni UI para editarlo todavía** (se cambia por BD).
10. **`lafayette:aislar-catalogo`:** clasifica por evidencia del historial; restaura el valor ICG MÁS ANTIGUO por clave y preserva las ediciones hechas por usuarios;
    `--permitir-puros-sin-evidencia` es explícito; el estado de pago de una OC se deriva de `purchase_order_payments` (no existe `payment_status`); bloqueo
    `SHARE ROW EXCLUSIVE` + huella del plan (si los datos cambian tras confirmar, aborta); auditoría en la misma transacción; respaldos `*_bak_scrum990`.
    Whitelist de entorno por `config('app.env')` (`local`, `testing` sin frase; otros exigen `APLICAR <app.env> <tenant>`), no por `app()->environment()`.
    Rol de migración vía `DB_MIGRATE_*` leído por config. `public static ?\Closure $despuesDeConfirmar` es solo una costura de pruebas.
11. **Rate limit (Bloque D):** `GET /bodega/zona-libre/productos` con `throttle:zona-libre-lectura` (60/min por `sub` del JWT) y `page` máx. 10000. `precio_5` sigue visible a `bodega.read`.

Notas para el runbook: ejecutar el comando con el rol `DB_MIGRATE_*`; pausar el sync durante `--apply`; definir retención/DROP de las tablas `*_bak_scrum990`.
No verificado: extractor contra la MariaDB real; el comando contra datos reales (primero dry-run en dev); cobertura exacta de `old_values` en auditoría real; efecto de anular
historial sobre estados de OC y `stock_quantity`.

## Tercera ronda — 2026-10-01
12. **MEDIO-A1:** `GET /compras/inventory/{id}/warehouse-stock` excluye la fila residual de Zona Libre (`enBodegaOperativa()`).
13. **N1/N8:** `lafayette:aislar-catalogo` fija `SET LOCAL lock_timeout` (`--lock-timeout`, 5 s) y `statement_timeout` (`--statement-timeout`, 600 s) antes del `LOCK TABLE`;
    expiración => mensaje claro + rollback. `--operador` es obligatorio con `--apply`. Ventana de mantenimiento en `docs/runbooks/lafayette-aislar-catalogo.md`.
14. **MEDIO-B1:** umbral `lafayette_guarda_min_ratio` por defecto 0,9 (migración 100400 sube el 0,5 sembrado sin pisar ediciones: `updated_by IS NULL`). Administrable en
    `PATCH /api/settings/system` (0,5..1,0, solo `superadmin.all`, 403 para otros con `settings.global`, auditado `settings.updated`); `GET /api/settings/system` lo devuelve.
15. **Precio:** `GET /bodega/zona-libre/productos` devuelve `precio: null` (sin consultar `precio_5`) y `meta.puede_ver_precio: false` sin `compras.read` ni `superadmin.all`;
    `sort=precio` sin ese permiso responde 422 (ordenar filtraría el precio). Se usó el permiso existente `compras.read`; no hizo falta uno angosto.
16. **B2** (guarda activa tras un recorte real) documentado en el runbook; docblock de `AdminContLocationController` corregido.

## Ajuste del comando por el dry-run real en TEST — 2026-10-02
17. **Puros referenciados => PROMOVER** (decisión de Luis): no bloquean ni se borran; pierden `external_source`/`external_synced_at` y conservan su dato; respaldo, verificación
    (existen, sin marca, valores y referencias idénticos, `cp = total - puros_borrados`) y reporte propio en el dry-run. La regla de evidencia se mantiene.
18. **`order_items` y otras tablas conservables contra Zona Libre** (`HISTORIAL_CONSERVABLE`) se aceptan con `--conservar-historial-zona-libre` sin tocarlas; sin la bandera, y para
    tablas desconocidas siempre, el comando rechaza. Se amplió además la verificación "restaurados" para excluir a los promovidos (conservan el proveedor Lafayette Lighting).
19. En dev ya se aplicó la versión anterior (nada que migrar); en prod las referencias a puros eran 0 en el diagnóstico. Detalle en `docs/runbooks/lafayette-aislar-catalogo.md`.
