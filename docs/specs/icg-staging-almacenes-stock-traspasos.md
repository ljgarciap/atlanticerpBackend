# Spec (Analista) — Extracción en crudo de almacenes, stock, traspasos y kardex de ICG a staging

- **Estado:** BORRADOR — pendiente de aprobación del Arquitecto y de Luis. No se escribe código sin esa aprobación.
- **Fecha:** 2026-10-13
- **Épica relacionada:** migración ICG→AtlanticERP (SCRUM-749). Ticket: por crear (no existe aún).
- **Contexto:** ICG se apagará. Hoy AtlanticERP solo importa el stock de AL4 (Bodega Central), pero ICG tiene
  stock vivo en 11 almacenes (~1,1M a costo fuera de AL4, medido 2026-10-12 sobre los 11 712 productos
  ya mapeados). El mapeo almacén ICG → bodega AtlanticERP está pendiente de confirmación del negocio
  (preguntas enviadas el 2026-10-13); **esta spec no depende de ese mapeo**.

## 1. Objetivo
Copiar a `legacy` (staging de solo referencia) todo lo necesario para reconstruir, después del apagado de
ICG, el stock, los traspasos y el kardex por almacén, sin decidir todavía qué almacenes refleja AtlanticERP.

## 2. Fuera de alcance
- No toca `bodega.warehouses` ni `product_warehouse_stock`, ni crea bodegas nuevas en AtlanticERP.
- No cambia la sincronización por delta de AL4 (SCRUM-1114), que sigue igual.
- No es la gemela de ICG ni el `.bak` (esos preservan todo; esto deja lo operativo consultable desde AtlanticERP).
- Las demás ~90 tablas con columnas de almacén quedan para una segunda fase.

## 3. Tablas ICG a extraer (verificado en vivo, 2026-10-13, solo lectura)

| Tabla ICG | Filas | PK en ICG | Notas |
|---|---|---|---|
| `ALMACEN` | 11 | `CODALMACEN` | Excluir la columna binaria `VERSION` (rompe `json_encode`). Incluye `SERIETRASPASOS`, `FECHABLOQUEO`, `ESMERMAS`, `ESPLANIFICACION`. |
| `STOCKS` | 181 727 | `CODARTICULO, TALLA, COLOR, CODALMACEN` | Todos los almacenes y todos los productos, sin filtrar por los ya mapeados. Incluye `FECHAMODIFICADO`, `UBICACION`, `MINIMO/MAXIMO`, `ENTRANSITO`, `MERMA`, `STOCKCONTABLE`. |
| `COSTESPORALMACEN` | 11 031 | `CODARTICULO, TALLA, COLOR, CODALMACEN` | Costo medio, de stock y último, en moneda e importe local (`*DMN`). Excluir `VERSION`. |
| `TRASPASOSCAB` | 7 253 | `SERIE, CAJA, NUMERO` | Copiar también los anulados (`ANULADO='T'`) y guardar `ESAJUSTE`, `ESRECUENTO`, `ESAUTOMATICO`, `RECIBIDO`. El filtrado se hace al analizar, no al copiar. |
| `INVENTARIOS` | 215 | `FECHA, CODALMACEN` | Solo cabeceras; `INVENTARIOSZONA` está vacía. |
| `MOVIMENTS` | 1 384 677 | `ID` | Kardex por movimiento (origen/destino, `TIPO`, `UNIDADES`, `STOCK` resultante, `SERIEDOC/NUMDOC`). **Es donde viven las líneas de los traspasos**: no existe `TRASPASOSLIN` ni `INVENTARIOSLIN`. |

**Descartadas con razón:** `STOCKSPORZ`, `REGULARIZACION`, `INVENTARIOSZONA`, `REPARTOSTOCK*`, `STOCKSFLAGS`,
`MOTIVOSTRASPASO*`, `TRASPASOSFIRMA` (todas con 0 filas). `STOCKSNEGATIVOSCOMO0` es una **tabla real, no una
vista** (181 727 filas, sin PK), idéntica a `STOCKS` más la columna `STOCKCORREGIDO`; difiere de `STOCKS` en
805 filas (las negativas). No se extrae como tabla propia: `STOCKCORREGIDO` (negativo tratado como 0) se
puede derivar al leer `STOCKS`.

## 4. Requisitos funcionales (propuestos)
- **RF1.** Una tabla de staging en el esquema `legacy` por cada tabla de la sección 3, con las columnas de
  ICG que sirven (sin binarias) y la misma clave natural como único. Nombres en snake_case en español,
  coherente con `legacy.productos`.
- **RF2.** Los extractores siguen el patrón del módulo (consulta pura, mapeo puro, conexión aparte; solo
  `SELECT` contra ICG, usuario `illumiread`).
- **RF3.** Carga idempotente por upsert sobre la clave natural; volver a correr no duplica.
- **RF4.** `MOVIMENTS` se carga por lotes y de forma incremental por `ID` (mayor `ID` ya cargado), por su
  tamaño; `STOCKS` y `COSTESPORALMACEN` son foto completa. Cada corrida se registra en `legacy.sync_runs`.
- **RF5.** Tabla `legacy.almacen_equivalencias` (`codalmacen_icg` único, `warehouse_id` de AtlanticERP **nullable**,
  `estado` propuesto/confirmado, `nota`). Se siembra con el mapeo propuesto marcado como **propuesto**; no
  se usa para mover stock hasta confirmar.
- **RF6.** Ningún comando escribe en ICG; lectura con timeout y sin bloquear tablas operativas.
- **RF7.** Un comando de verificación compara conteos y suma de `STOCK` por almacén entre ICG y staging.

## 5. Criterios de aceptación
1. Tras una corrida completa, `legacy` tiene 11 almacenes, 181 727 filas de stock y 11 031 de costos, con
   conteo y suma de `STOCK` por almacén iguales a ICG (RF7).
2. Una segunda corrida inmediata no cambia ningún conteo (idempotencia).
3. `TRASPASOSCAB` incluye anulados; el conteo coincide con ICG (7 253).
4. `MOVIMENTS` queda completo (1 384 677 filas a la fecha) y una corrida posterior solo trae `ID` nuevos.
5. Con `illumiread` sin permiso de escritura, ningún camino del código intenta escribir en ICG.
6. Negativo: si la conexión a ICG falla a mitad de `MOVIMENTS`, la corrida queda `partial` y la siguiente
   continúa desde el último `ID` cargado, sin duplicados.
7. Negativo: un almacén en `STOCKS` que no exista en `ALMACEN` (caso conocido: **A1**) se carga igual y queda
   marcado como "huérfano" en la verificación, sin abortar la corrida.
8. Nada de lo cargado cambia `catalog_products.stock_quantity` ni `product_warehouse_stock`.

## 6. Decisiones de Luis (2026-10-13)
- `MOVIMENTS`: **completo** (todos los `TIPO`, incluida la carga inicial).
- Foto de `STOCKS` y `COSTESPORALMACEN`: **diaria** hasta el corte.
- Entornos: **dev primero, luego test y prod** por el flujo normal.

**`MOVIMENTS.TIPO` medido (solo lectura):** `REG` 899 877 (2013-03→2025-08, casi todo sin serie de documento),
`TMP` 461 790 (fechas desde 1900 hasta 2025-04, 461 789 sin serie: parece temporal/basura), `ENV` 22 999
(2012-07→2026-10-09, **son los traspasos**: con `SERIEDOC` T1…T8/TB/TS y almacén origen→destino), `MOD` 11.
Las 7 253 cabeceras de `TRASPASOSCAB` tienen líneas en `MOVIMENTS` (join por `SERIEDOC=SERIE`, `NUMDOC=NUMERO`).
Como se decidió cargar todo, `TMP` se copia pero se marca en la columna `tipo` para excluirlo al analizar.

## 7. Preguntas abiertas
- **Técnicas (Arquitecto):** estimar disco de `MOVIMENTS` completo y duración de la primera carga; la
  frecuencia diaria de `STOCKS` (181 727 filas) debe medirse contra la carga que añade sobre ICG.
- ¿Qué significan `REG` y `MOD`? Se copian igual; el significado se documenta al analizar.
- **De negocio (ya enviadas):** equivalencias de AL2, AL3, ALR, A1, ALM, ALX/ALY, AL1, B1/B2 y si AtlanticERP
  debe reflejar todos los almacenes o solo AL4. Pueden responderse después de aprobar esta spec.

## 8. Riesgos
- Primera carga larga de `MOVIMENTS` contra una base de producción de ICG de ~113 GB de archivos: correr
  fuera de horario y por lotes.
- `illumiread` no tiene `VIEW DATABASE STATE`: los conteos salen de `COUNT(*)`, no de catálogos del sistema.
- Cualquier producto fuera del catálogo mapeado queda en staging sin `catalog_product_id`; es esperado.
