# SCRUM-990 — Senior Review ronda 4 (delta 8ed2fb17 + 39cdac39)

Alcance: promoción de puros referenciados y `--conservar-historial-zona-libre` en `app/Console/Commands/LafayetteAislarCatalogoCommand.php`, tests nuevos y runbook.
Solo lectura: no se corrió la suite (ya verde: 4442 pruebas, PHPStan 8 limpio, según el solicitante). Revisión por lectura completa del comando.

**Veredicto: APROBADO** (sin CRÍTICO ni ALTO; 2 MEDIO no bloqueantes con mitigación operativa, 5 BAJO).

## Respuestas a las preguntas

1. **Clasificación PROMOVER.** Correcta. `columnasQueReferencian()` (:483) descubre por `pg_constraint` (FK) y por `information_schema.columns` (columna `catalog_product_id` sin FK) en todos los schemas del tenant; los nombres vienen del catálogo de Postgres, no de entrada de usuario, y los ids se castean a int. Excluye respaldos y el stock. `lafayette_productos` no tiene `catalog_product_id`, así que no promueve todo por error. Dos matices:
   - Stock en otra bodega a 0: se promueve (cualquier fila cuenta, como bloqueaba antes). Es la opción conservadora (no destruye nada); el costo es residuo, no pérdida. Aceptable.
   - Puro referenciado SOLO por historial que el comando anula (ver MEDIO-1): se promueve y tras el paso 5 queda sin referencias. Es seguro pero deja basura sin marca; antes bloqueaba.
2. **Transacción.** Correcta. Respaldo de todo el origen (incluye promovidos) en el paso 1; UPDATE (:1034) acotado a `puros_promover` (no al origen entero; los restaurables ya salieron del origen en el paso 2 y los puros son disjuntos de enlazados); el DELETE del paso 4 ya no los ve porque perdieron la marca; `verificar()` valida existencia sin marca, valores vs respaldo, conteo de referencias, `cp = total - puros_borrar`, `restante = 0`, y todo falla con ROLLBACK. La huella incluye `puros_promover` y se recalcula bajo bloqueo (las tablas de `refs` están bloqueadas). Una referencia nueva entre confirmar y aplicar mueve el puro de borrar a promover y cambia el md5, por tanto aborta. Idempotente: segunda corrida => `nada_que_hacer`.
3. **Conservables.** Correcto que se acepten sin tocarlas (la bodega y su FK siguen existiendo). Con la bandera, solo las 4 tablas de la lista pasan; el resto cae a `otras` y bloquea (también con la bandera, probado). Sin bandera, las 4 bloquean. Riesgos en MEDIO-2 y BAJO-3.
4. **"Restaurados" sin promovidos.** No deja ICG sin verificar: los restaurables están en `linked`/`enlace`, los puros no, así que los conjuntos son disjuntos; al excluir por id solo se sacan los promovidos. Con lista vacía usa `-1`.
5. **Auditoría/mensajes/dry-run.** `puros_promovidos` e `ids_puros_promovidos` (cap 200) en el evento; los errores de verificación no llevan SQL; el dry-run solo hace SELECT (test lo prueba con foto de BD y conteo de auditoría). Runbook coincide con el código (fecha "2026-10-02" cuando los commits son del 2026-10-01: cosmético).
6. **Tests.** Corren sobre BD real (quote_items, order_items, family_items, trigger de mutación para forzar el ROLLBACK, tabla desconocida real con FK). La comprobación de mutación es creíble por lectura: sin el paso 2b el DELETE borraría el puro y `quote_items.nullOnDelete` cambiaría las referencias, lo que rompe los tests de exit 0 y de conteos; sin `order_items` en la lista se rompen el test sin/con bandera y el del dry-run con bandera (2). No ejecuté la mutación. Huecos en BAJO-4.

## Hallazgos

### MEDIO-1 — Puro referenciado solo por historial anulable se promueve y queda huérfano
`LafayetteAislarCatalogoCommand.php:394-420` (`conjuntos`, `refs` incluye `inventory_movements`, `purchase_order_receipt_lines`, `customer_returns`, `general_count_requests`) y `:1190-1200` (`verificar` salta las tablas anuladas). Sin la bandera, el paso 5 borra esas filas después de promover, y el producto queda sin marca y sin usos: residuo de Lafayette dentro del catálogo de AtlanticERP que el aislamiento debía borrar. No hay pérdida de datos ni inconsistencia verificable.
Fix: (a) mínimo, que `reportar()` etiquete esos puros ("se promueve; quedará sin referencias al anular el historial") y el runbook lo diga; (b) completo, no contar como uso las columnas de tablas de historial cuyas filas se anulan (solo-ZL) y mover el paso 5 antes del 4 (hoy un DELETE de puro con FK RESTRICT fallaría, por eso estaba bloqueado). Antes de aplicar en test, mirar la tabla "usado por" del dry-run: si algún promovido solo aparece en esas 4 tablas, decidir.

### MEDIO-2 — Conservables sin estado: solicitudes/pedidos activos quedan contra una bodega sin stock
`:753-762` y `:106-114`. El dry-run lista solo tabla y filas. `relocation_requests`, `adjustment_request_lines` y los pedidos con `order_items` en etapa activa (p. ej. el pedido 96 en Asignado) quedan apuntando a Zona Libre, donde ya no hay stock: despachar/aprobar fallará o dejará negativos. Luis decidió conservar `order_items` como historial, pero nada comprueba que sea historial.
Fix: en el dry-run listar por tabla el estado de las filas (etapa del pedido, `status` de la solicitud) y advertir (o bloquear sin una bandera adicional) cuando haya pendientes. Operativo, antes de aplicar en test: verificar la etapa de los pedidos de order_items 56 y 70 y del pedido 96.

### BAJO-1 — La regla de evidencia bloquea innecesariamente a los promovidos
`:864` cuenta `puros_sin_evidencia` sobre todos los puros. Promover no destruye nada, así que la evidencia solo debería exigirse a `puros_borrar`. Si #12900/#22454 no tienen evento `created`, hará falta `--permitir-puros-sin-evidencia`. Conservador y documentado; opcional.

### BAJO-2 — Verificación de valores de promovidos incompleta
`:1176-1181` compara 5 campos; faltan `brand`, `photo_url`, `category` y el resto. Fix: comparar `to_jsonb(c) - 'external_source' - 'external_synced_at' - 'updated_at'` contra el respaldo.

### BAJO-3 — Conservables por nombre corto de tabla, sin schema
`:755` usa `$corto`; una tabla `order_items` de otro schema con FK a `warehouses` se aceptaría. Hoy solo existe en `bodega`. Fix: comparar `"{$slug}_bodega.{$corto}"`. (Preexistente, sin cambio: tablas con `warehouse_id` sin FK no se detectan.)

### BAJO-4 — Casos de test que faltan
Promovido que además tiene stock en Zona Libre (el stock ZL debe borrarse y no contar como cambio de referencias); puro referenciado solo por una tabla desconocida con `catalog_product_id` (descubrimiento dinámico); referencia nueva entre confirmar y aplicar usando `$despuesDeConfirmar` (huella); puro con proveedor distinto de Lafayette; puro referenciado solo por historial anulable (MEDIO-1).

### BAJO-5 — `ids_puros_promovidos` truncado a 200
`:1068`. Con más, la lista completa solo se reconstruye por exclusión desde el respaldo. Sin impacto con los volúmenes vistos (2 en test, 0 en prod).

## Regresión sobre dev
Ninguna. La versión anterior bloqueaba cualquier puro referenciado, así que dev no tiene promovidos; una nueva corrida en dev da `nada_que_hacer` (origen 0). Prod: 0 referencias a puros, plan igual salvo que `order_items` contra Zona Libre siga bloqueando sin la bandera (correcto).

## Pase
- **Push a dev: sí.** **Push a test: sí**, y luego repetir el dry-run en test con `--conservar-historial-zona-libre`, revisar la tabla de promovidos y las etapas de los pedidos (MEDIO-1/2) antes de `--apply`.
- **master: sí** una vez validado en test; en prod el comando no cambia el plan (0 referencias). Los MEDIO pueden ir como seguimiento si Luis acepta el residuo.
