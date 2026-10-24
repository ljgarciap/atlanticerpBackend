# Runbook — `legacy:fix-clientes-ruc` (SCRUM-1137, Fase 3, épica SCRUM-749)

`CLIENTES.CIF` de ICG trunca el RUC a 12 caracteres; el valor completo vive en `CLIENTES.NIF20` (ya normalizado en `legacy.clientes.ruc` / `ruc_dv`). Los `ventas_diseno.sub_clients`
ya enlazados a esos clientes de ICG pueden haber quedado con el `tax_id` truncado. Este comando lo corrige **tocando datos vivos**, con dry-run por defecto, auditoría y reversa por corrida.

## Qué hace
Candidatos: `legacy.clientes` con `sub_client_id` no nulo y `match_status` `matched` o `promoted` (nunca `unmatched`/`ambiguous`), `ruc_estado = ok`, `ruc_origen = nif20`.
Solo corrige un sub_client si se cumplen todas estas precondiciones; si no, lo omite y el CSV dice por qué (`motivo`):

1. **(a)** el `tax_id` actual del sub_client, normalizado (mayúsculas, sin puntos ni guiones), es igual al CIF normalizado del cliente de ICG: no fue editado a mano ni es otro RUC (`tax_id_distinto_del_cif`).
2. **CIF truncado:** el CIF crudo (sin espacios en los extremos) tiene exactamente 12 caracteres, el ancho de `CLIENTES.CIF`. Un CIF más corto no está truncado (`cif_no_truncado`).
3. **(b)** el RUC completo normalizado empieza con el CIF normalizado y es más largo (`ruc_no_extiende_al_cif`).
4. **(c)** el sub_client está enlazado a un solo cliente de ICG (o a varios con el mismo RUC; entonces se corrige una sola vez). Si dos clientes de ICG comparten sub_client con RUC distintos, se omite (`sub_client_compartido_con_ruc_distinto`).
5. **Sin documentos fiscales:** el sub_client no tiene facturas ni notas de crédito `certificado` o `pendiente` (`tiene_documentos_fiscales`). Es la misma regla de `SubClientTaxDataService` (el receptor de la factura, el PDF y el libro leen `sub_clients` en vivo). Se verifica en el plan y de nuevo dentro de la transacción de cada sub_client; también bloquea `--revert`.
6. **DV coherente:** si el sub_client ya tiene DV y ICG trae uno distinto, se omite (`dv_distinto`); no se cambia solo el RUC dejando un DV viejo. Si coincide, se corrige el RUC.
7. Otro sub_client no tiene ya el RUC nuevo (`conflicto_otro_sub_client_tiene_ese_tax_id`) y dos propuestas no apuntan al mismo valor.

El DV solo se escribe si ICG lo trajo (`ruc_dv`) y el `tax_id_dv` actual está vacío: nunca se inventa ni se pisa. Los clientes con `ruc_estado = revisar` o `truncado_posible`
(p. ej. `RUC 1671600-`, `155662320 2`, `6-712-881`) **nunca se tocan**: salen en el CSV con `accion = omitir`.

La aplicación usa `SubClient::save()` (queda `ventas_diseno.sub_client.updated` en `audit_logs` con valor anterior y nuevo), una transacción por sub_client, el registro de reversa en
`legacy.cliente_ruc_correcciones` y una verificación releyendo la fila. La corrida real se registra en `legacy.sync_runs` (entidad `clientes_ruc_fix`) al inicio y se cierra al final
(`success`, `partial` o `failed`), y deja un evento resumen `legacy.fix_clientes_ruc`. Es idempotente: un segundo `--apply` no cambia nada.

## Uso

    php artisan legacy:fix-clientes-ruc --tenant=atlantic                    # dry-run: no escribe en la BD; imprime «A corregir: N. Huella del plan: xxxxxxxxxxxx» y deja el CSV
    php artisan legacy:fix-clientes-ruc --tenant=atlantic --apply --operador="<nombre>" --esperado=<huella> --confirmar="APLICAR <entorno> <tenant>"
    php artisan legacy:fix-clientes-ruc --tenant=atlantic --revert=<run_id> --operador="<nombre>"

`--apply` exige `--esperado=<huella>` (la del dry-run revisado) y la frase `--confirmar="APLICAR <entorno> <tenant>"` (p. ej. `APLICAR production atlantic`). Si el plan recalculado
no coincide con la huella, aborta con exit 1 sin escribir: significa que algo cambió desde la revisión (un sync de ICG, una edición); repite el dry-run y revisa el CSV de nuevo.
`--yes` reemplaza a `--confirmar` solo en local y testing. El `run_id` se imprime ANTES de escribir nada: si el proceso se corta a mitad, lo ya aplicado se revierte con ese `run_id`.

La consola solo muestra conteos, códigos (`icg_codcliente`, `sub_client_id`) y motivos; nunca RUC completos ni nombres. El CSV (columnas `icg_codcliente, sub_client_id, tax_id_actual,
tax_id_propuesto, dv_actual, dv_propuesto, accion, motivo`) sí trae los valores: se escribe en `storage/app/private/legacy/ruc-correcciones-<run_id|dry>.csv` (disco local privado, ignorado
por git; `--csv=` solo acepta rutas dentro de `storage/app/private`). Las celdas que empiezan por `=`, `+`, `-`, `@`, tab o retorno llevan una comilla simple delante para que Excel/Sheets no las
interprete como fórmulas (por eso un valor que empieza por `-` se ve como `'-...` en el CSV).

## Orden exacto por entorno (dev, luego test, luego prod)
1. Deploy del código (extractor, normalizador, Matcher, migraciones).
2. `legacy:sync-incremental-clientes --dry-run`: fija los hashes y puebla `nif20`/`ruc`/`ruc_dv`/`ruc_estado` en `legacy.clientes`. Esa primera corrida incremental queda retenida por el circuito (más del 20 % de cambio): es esperado.
3. **Importante:** tras el re-baseline, el incremental no vuelve a correr el Matcher sobre los clientes existentes (el hash ya quedó fijado). Para que el Matcher aplique el RUC completo a clientes ya cargados hay que
   correr `legacy:sync-clientes` completo (que sí ejecuta el Matcher) o, como mínimo, revisar el efecto con el paso 4 antes de decidir.
4. `legacy:preview-clientes-match` (solo lectura): revisar cuántos clientes cambian de estado y, sobre todo, `cambian_de_sub_client`.
5. `legacy:fix-clientes-ruc` en dry-run: revisar el CSV (incluidos los `omitir`); anotar la huella.
6. **Luis revisa el CSV.** Nada pasa al siguiente entorno sin esa revisión.
7. `--apply` con `--esperado=<huella>` y la frase de confirmación. En dev, probar además la reversa de punta a punta (`--revert`) y volver a aplicar.
8. Test igual que dev. **prod:** solo con aprobación expresa de Luis y **corrido por Luis**, con el CSV de test ya revisado, fuera de las horas de los syncs agendados (prod 21:00) y de fusiones o altas masivas de clientes.
9. Antes de prod: **avisar a Contabilidad** de que cambia el RUC mostrado en el libro de facturas y en las pantallas de clientes para los clientes corregidos (los que tienen documentos fiscales quedan fuera, ver arriba).
10. Borrar el CSV de `storage/app/private/legacy/` una vez revisado y aplicado (ver límites conocidos).

## Cotizaciones (`--cotizaciones`) — se corre DESPUÉS del apply de sub_clients
Cada cotización guarda su propia copia del RUC al crearse (`quotes.ruc`) y el PDF de la cotización lee esa copia; la factura, su PDF y el libro leen el RUC vivo del cliente. Corregir `sub_clients`
no toca esas copias, así que una cotización vieja seguiría mostrando el RUC truncado. Decisión de Luis: ajustarlas al RUC corregido.

    php artisan legacy:fix-clientes-ruc --tenant=atlantic --cotizaciones                    # dry-run: «A corregir: N. Huella del plan: xxxxxxxxxxxx» + CSV legacy/cotizaciones-ruc-dry.csv
    php artisan legacy:fix-clientes-ruc --tenant=atlantic --cotizaciones --apply --operador="<nombre>" --esperado=<huella> --confirmar="APLICAR <entorno> <tenant>"
    php artisan legacy:fix-clientes-ruc --tenant=atlantic --revert=<run_id_de_cotizaciones> --operador="<nombre>"

Mismo protocolo que sub_clients (dry-run por defecto, huella de 12 hex, frase de confirmación, `run_id` impreso antes de escribir, `Quote::save()` con auditoría `ventas_diseno.quote.updated`, una transacción
por cotización, registro de reversa en `legacy.cotizacion_ruc_correcciones`, corrida en `sync_runs` con entidad `cotizaciones_ruc_fix`, consola solo con conteos y códigos, CSV privado).

Qué se toca: para cada corrección de sub_client NO revertida (de cualquier corrida), si el sub_client todavía tiene exactamente el RUC corregido, sus cotizaciones cuyo `ruc` normalizado es igual al
`tax_id` truncado anterior. Se consultan sin scopes globales (también las de CotizaYA y cualquier otro origen). Una cotización con otro `ruc` (editado a mano, vacío, de otra empresa) **nunca se toca**
(`ruc_distinto_del_truncado` en el CSV); las que ya tienen el RUC completo salen como `ya_igual_al_ruc_completo`. Es idempotente: tras aplicar, el dry-run da 0.

Reversa: `--revert=<run_id>` mira dónde está registrado el run_id; uno de cotizaciones restaura solo las que siguen valiendo el RUC aplicado (nunca pisa una edición posterior) y uno de sub_clients **no toca
cotizaciones**. Para deshacer todo: primero se revierte el run de cotizaciones y después el de sub_clients. El orden se impone: `--revert` de un run de sub_clients **omite** (motivo `cotizaciones_sin_revertir`)
cada sub_client que aún tenga correcciones de cotizaciones sin revertir; el sub_client queda como estaba y se reintenta el revert tras revertir las cotizaciones.

Las cotizaciones se guardan sin mover su `updated_at` (corregir el RUC no es una edición de la cotización); el cambio queda en `audit_logs` (`ventas_diseno.quote.updated`, con `ruc` anterior y nuevo) y en
`legacy.cotizacion_ruc_correcciones`. La auditoría es best-effort (si falla, no aborta el guardado): el registro de reversa, que sí es transaccional, es la fuente de verdad de qué cotizaciones se tocaron.

Orden por entorno: tras el paso 7 (apply de sub_clients) y antes de pasar al entorno siguiente, `--cotizaciones` en dry-run → revisar el CSV → apply. Mismas reglas para prod: solo con aprobación expresa de Luis y corrido por Luis.

## Reversa
`--revert=<run_id>` restaura exactamente `tax_id` y `tax_id_dv` anteriores, **solo** de lo que sigue siendo igual al valor nuevo; si alguien lo editó después, lo omite y lo reporta (nunca pisa
una edición posterior). Si el sub_client ganó facturas o notas de crédito `certificado`/`pendiente` desde la corrección, también lo omite (`tiene_documentos_fiscales`). Marca `revertida_at` y es
idempotente. Cada restauración también queda en `audit_logs`.

## Qué NO hace
- No consulta la DGI ni calcula DV. No toca `legacy.*` salvo el registro de reversa y `sync_runs`.
- No corrige casos `revisar`/`truncado_posible`, sub_clients editados a mano, con documentos fiscales, ni clientes `unmatched`/`ambiguous`.
- No agrega el DV a un sub_client cuyo `tax_id` ya es igual al RUC completo pero no tiene DV, aunque ICG lo traiga: **pasada aparte pendiente de decisión de Luis** (con su propio `run_id` y reversa; solo si `tax_id` ya es igual al RUC de ICG, DV vacío y sin documentos fiscales).

## Límites conocidos
- No hay índice único en `sub_clients.tax_id`: la unicidad se verifica dentro de la transacción de cada sub_client, que no bloquea otras filas. No correr con sincronizaciones, fusiones ni altas de clientes activas.
- La comparación de RUC existentes normaliza en SQL (para detectar duplicados al aplicar) y en PHP (en el plan); en casos exóticos (tabulaciones, mayúsculas no ASCII, un `DV` pegado dentro del `tax_id`) pueden diferir.
- Un RUC que resulta ambiguo en el Matcher (coincide con dos sub_clients) corta el intento por CIF: no se prueba el CIF después. Verlo en `legacy:preview-clientes-match`.
- El CSV queda en `storage/app/private/legacy/` con los permisos por defecto del disco y sin política de borrado: borrarlo tras la revisión. `--csv=` puede sobrescribir un archivo existente dentro del storage privado.
- Si el proceso se mata (ssh cortado, kill), el `sync_runs` queda en `partial` y no se escribe el evento resumen, pero cada fila aplicada ya tiene su registro de reversa y su auditoría bajo el `run_id` impreso al inicio.

## Comprobaciones pendientes antes de prod (Pre-QA)
- Las cotizaciones guardan su propio RUC al crearse y el PDF lee esa copia: se ajustan con `--cotizaciones` (ver arriba). Verificado por el Pre-QA: ningún código compara la copia con el RUC vivo.
- Probar la facturación electrónica con un cliente corregido en dev y si rechaza clientes sin DV (los corregidos sin `ruc_dv` de ICG seguirán sin DV).


## Notas de la segunda revisión

- La huella (12 hex) cubre, por cada sub_client a corregir, el `tax_id` y el DV actuales y los nuevos.
- Aplicar fuera del horario de facturación: entre la última verificación de documentos fiscales de cada cliente y su guardado puede certificarse una factura (ventana de milisegundos, sin candado entre módulos). El comando ya omite los clientes con documentos `certificado` o `pendiente` al planificar y al guardar.
- `--revert` no pide huella ni confirmación (restaura el estado previo y exige `--operador`), pero omite lo editado después o con documentos fiscales nuevos.
- `legacy.sync_runs` no guarda el `run_id`. Si se pierde la consola, se recupera por el nombre del CSV (salvo con `--csv` personalizado) o con `SELECT DISTINCT run_id FROM legacy.cliente_ruc_correcciones ORDER BY aplicada_at DESC`.
- En ambientes distintos de producción la frase usa el nombre del entorno de la app (`qa` en test.atlanticerp.ai, `dev` en dev.atlanticerp.ai, `production` en prod). El comando imprime la frase exacta si no coincide.
