# SCRUM-990 — Aislar los productos de Lafayette (Zona Libre) del inventario de Atlantic

Spec del Analista. Estado: borrador para aprobación de Luis. Reportó Gerencia Test (prioridad Highest).
Épica: SCRUM-329. Relacionado: SCRUM-969/970/971/972/973 (integración Lafayette Lighting).

## Decisión de Luis (2026-09-30)
Los productos de Lafayette los controla otra empresa y otro sistema. En vez de filtrar en cada pantalla,
se **aíslan en una tabla propia**:
- Los productos de Lafayette viven solo en una tabla nueva (con su stock y su precio), separada del catálogo de AtlanticERP.
- Las vistas de la bodega Zona Libre consultan esa tabla para que el usuario valore si el producto, su stock y su
  precio le sirven. Es de solo consulta.
- Si un producto se quiere usar en AtlanticERP, el usuario encargado lo **crea a mano** como producto de AtlanticERP.
- La data de AtlanticERP se limpia para **conservar su origen**: lo de ICG como viene de ICG, lo de Lafayette como viene de Lafayette.
- Todo lo cargado hasta ahora es de prueba (sin documentos ni transacciones reales), por lo que la limpieza no necesita preservar referencias.

## Estado real en dev (2026-09-30, solo lectura)
| Hecho | Valor |
|---|---|
| Productos de origen Lafayette en `catalog_products` | 25.816 |
| Creados por el sincronizador (2026-09-26), puros Lafayette | 17.815 |
| Productos ICG preexistentes (creados 2026-08-12) que el sincronizador "vinculó" y sobrescribe | ~8.001 |
| Filas de stock en "Bodega Zona Libre" | 25.833 (1.767.865 unidades) contra 85.402 de Bodega Central |
| Productos de origen Lafayette con stock en otra bodega | 2.724 |
| Productos de origen Lafayette referenciados por operaciones de prueba | 4 (mas 7 lineas de cotizacion de prueba) |
| Fuente ICG original intacta | `legacy.productos` (12.310 filas), 8.001 enlazadas por `catalog_product_id` |

Causa: el loader (`CatalogProductLoader`) escribe en el catalogo compartido y en `product_warehouse_stock`,
asigna provider Lafayette a productos ICG existentes y los actualiza en cada corrida.

## Alcance
1. **Tabla propia de productos de Lafayette** (nombre y esquema a definir por el Arquitecto) con los campos de
   `vista_productos_lighting` que ya se mapean: id de Lafayette, referencia, descripcion, marca, categoria, url de imagen,
   stock/disponible, precio (hoy `precio_5`, decision reversible de Luis 2026-09-25), fecha de sincronizacion.
2. **Reescribir el pipeline `lafayette:sync-productos`** para escribir solo en esa tabla (upsert por id de Lafayette).
   Desaparecen las ramas created/linked/coexisting sobre `catalog_products` y la escritura en `product_warehouse_stock`.
3. **Vistas de Zona Libre** (Bodega > Vista Bodegas > Zona Libre) leen de la tabla nueva: listado paginado, busqueda, detalle,
   con el indicador claro de "solo consulta". Incluye productos con stock 0 (decision de Luis 2026-09-26, SCRUM-973).
4. **Sin endpoints de escritura** sobre esa tabla: no hay edicion, ajuste, conteo, reubicacion ni reserva posible por construccion.
5. **Limpieza de datos existentes** (destructiva, con respaldo y confirmacion explicita de Luis):
   - Eliminar de `catalog_products` los ~17.815 productos puros Lafayette y sus filas en `product_warehouse_stock` (Zona Libre).
   - Restaurar los ~8.001 productos ICG al estado original desde `legacy.productos` (nombre, marca, precio, foto, proveedor,
     marcas `external_*`), quitandoles el proveedor Lafayette y su fila de stock en Zona Libre.
   - Tratar las referencias de prueba (4 productos, 7 lineas de cotizacion) segun lo que decida el Arquitecto (anular o eliminar datos de prueba).
6. **Crear producto en AtlanticERP a partir de Lafayette**: alta manual por el usuario encargado (rol y forma por confirmar, ver preguntas).

## Criterios de aceptacion
- [ ] Los productos de Lafayette existen solo en la tabla propia; `catalog_products` y `product_warehouse_stock` no contienen filas de origen Lafayette.
- [ ] Bodega > Vista Bodegas muestra las bodegas de Atlantic sin cambios y "Zona Libre" como bodega de solo consulta, leyendo de la tabla Lafayette.
- [ ] Ninguna edicion, movimiento, ajuste, conteo ni reserva es posible sobre productos de Zona Libre (ni por UI ni por API).
- [ ] Compras > Catalogo y Stock > Inventario y Bodega > Inventario > Ver Inventario muestran solo inventario de Atlantic; Zona Libre no aparece ni suma.
- [ ] Ningun total, disponible, cantidad, valor de inventario ni reporte de Atlantic (incluido Gerencia) incluye datos de Lafayette.
- [ ] Bodega Central, Showroom Obarrio, Showroom SM, Showroom Cliente, Merma, Reclamos y Devoluciones y Reserva Servicios se comportan igual que antes; Reserva Servicios sigue sin contar como disponible.
- [ ] Los productos ICG recuperan sus datos originales y dejan de ser sobrescritos por la sincronizacion.
- [ ] La sincronizacion programada deja de tocar `catalog_products` y `product_warehouse_stock`.
- [ ] Listado de Zona Libre con el catalogo completo (~25.800 filas) cumple p95 < 500 ms y pico < 64 MB (regla de rendimiento del proyecto).
- [ ] Pruebas de regresion: por cada totalizador, con datos de Lafayette cargados, el total de Atlantic no cambia.

## Decisiones de Luis (2026-09-30), resueltas
- **Alta en AtlanticERP:** totalmente manual, sin precarga ni boton "crear a partir de este producto".
- **Quien crea:** los permisos que ya permiten crear productos hoy; no se agrega ningun permiso nuevo.
- **Ventas & Diseno:** solo cotiza con productos de AtlanticERP. Dejan de estar disponibles los de Lafayette hasta que se creen a mano.
  Las lineas de cotizacion de prueba que los usan se tratan como datos de prueba.
- **Precio en Zona Libre:** solo `precio_5`, etiquetado como precio de Lafayette (decision reversible del 2026-09-25).
- **Test y prod:** el diagnostico de solo lectura se hace despues, cuando el Arquitecto presente el diseno y se apruebe el plan.
  El diseno parte de los datos de dev; nada se limpia en test ni prod sin diagnostico previo, respaldo y confirmacion explicita de Luis.
