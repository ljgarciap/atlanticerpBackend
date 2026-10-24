# Senior Review: registro de transmisiones al PAC (SCRUM-987)

**Fecha:** 2026-09-29 · **Revisor:** Senior Reviewer (subagente) · **Spec:** `docs/specs/fe-transmisiones-log-payload-respuesta.md`
**Alcance:** solo los archivos del batch (se ignoró el WIP ajeno de Catálogo, CrmSettings y config de horizon/queue).
**Evidencia usada:** la que entregó el Arquitecto (PHPStan L8 limpio en todo el proyecto; 142 tests backend filtrados en verde; suite completa con 5 fallos, todos en `tests/Feature/VentasDiseno/Catalog*`, que son WIP ajeno; vitest 2088/2088; tsc limpio). No se volvió a correr: la revisión es de lectura del código completo de cada archivo tocado y de sus callers.

## Decision: CHANGES REQUESTED

Hay 2 blockers. Los dos son de una línea o pocas, y ninguno cambia el diseño. El resto está sólido: las rutas quedan de verdad bajo `primary_approver_only`, la API key se redacta antes de truncar, el polling registra cada consulta (incluidas las `pendiente` y `error_configuracion`), el DTO no rompe Digifact y el visor escapa todo como texto.

---

## Blockers

### B1. Si falla el INSERT, el log de error puede volcar la PII del receptor en laravel.log
`app/Modules/AdminCont/FacturacionElectronica/Services/FeTransmisionRecorder.php:60-65`

```php
} catch (Throwable $e) {
    Log::error('...no se pudo registrar la transmisión al PAC', [
        'tipo' => $tipoDocumento, 'id' => $modelo->id,
        'exception' => $e->getMessage(),
```

El fallo más probable acá es una `QueryException`. Su `getMessage()` trae el SQL con **los bindings interpolados**, o sea el `request_payload` y el `response_body` completos (RUC, nombre, correo y dirección del receptor). Esos datos terminan en `laravel.log`, y de ahí en CloudWatch `/atlanticerp/prod`, con 365 días de retención. La spec lo prohíbe explícitamente ("No se replica a logs ni a `audit_logs`"). Hay además un disparador real y fácil de alcanzar: jsonb de Postgres rechaza `\u0000`. Un texto del PAC o un dato del cliente con un byte NUL hace fallar el INSERT (ver S2).

**Fix:** no loguear el mensaje. Alcanza con `'exception_class' => $e::class` más, si es `QueryException`, `'sqlstate' => $e->getCode()` (o `$e->errorInfo[0] ?? null`). Test: forzar una `QueryException` (por ejemplo con `\u0000` en `detalles`) y comprobar con `Log::spy()` que ningún contexto logueado contiene el RUC ni el nombre del receptor.

### B2. Al aprobar la NC desaparece el visor, justo cuando se acaba de certificar
`app/Modules/AdminCont/Http/Controllers/AdminContCreditNoteController.php:229-234`, junto con `atlanticerp-frontend/src/hooks/useAdminContab.ts:724` (`qc.setQueryData(notaCreditoDetailKey(note.id), note)`).

`decision()` es `primary_approver_only` y arma la respuesta con `show()` + `fe_detalle`, pero **no** agrega `puede_ver_fe_transmisiones`. El frontend reemplaza la caché del detalle con esa respuesta más angosta. El flag queda `undefined` y el visor se desmonta (`DetalleNotaCreditoModal.tsx:231`) en el momento exacto en que aprobar disparó la certificación síncrona (`CreditNoteService::decide()` → `certificarSiCorresponde()`), que es cuando Mark más lo necesita. Es el mismo patrón de la memoria `feedback_tanstack_query_setquerydata_narrow_mutation_response` y el mismo hueco que el batch anterior tuvo con `fe_detalle`.

**Fix:** en `decision()`, dentro del `if ($result !== null)`, agregar `$result['puede_ver_fe_transmisiones'] = true;` (la ruta ya es `primary_approver_only`). Sumar la aserción al test existente `test_show_y_decision_exponen_fe_detalle_solo_a_mark` (`tests/Feature/AdminCont/AdminContCreditNoteControllerTest.php`, bloque de `$decision`).

---

## Suggestions

### S1. Blindar el INSERT con un savepoint (transacción de Postgres abierta)
`FeTransmisionRecorder.php:43` (`AdminContFeTransmision::create(...)`), llamado ANTES de `persistirResultado()` en `FiscalCertificationService.php:119` y en `PollMslDocumentEstadoJob.php:83`.

Revisé los callers de hoy y ninguno certifica con una transacción abierta: `InvoiceService::confirm()` despacha `InvoiceCreated` después de su `DB::transaction`, y `CreditNoteService` llama a `certificarSiCorresponde()` fuera de las transacciones de las líneas 230, 442 y 982. El try/catch alcanza **hoy**. Si mañana alguien certifica dentro de una transacción, un error SQL en este INSERT la aborta (`25P02 current transaction is aborted`). Entonces falla el `forceFill()->save()` de `persistirResultado()`, que no está atrapado en `certificar()`, y se pierde el `fe_estado`: se rompería la regla dura. El test `test_un_fallo_al_registrar_nunca_bloquea_la_certificacion` no lo detectaría, porque simula el fallo con una excepción PHP en `creating`, antes del SQL.

**Fix (barato):** envolver el `create()` en `DB::transaction(fn () => AdminContFeTransmision::create([...]))`. Con una transacción externa abierta, Laravel usa `SAVEPOINT` / `ROLLBACK TO SAVEPOINT` y la externa queda sana. Sin ella, es un commit normal. Otra opción: registrar DESPUÉS de `persistirResultado()`, pero en el polling eso obliga a registrar también en las ramas que lanzan o retornan, así que el savepoint es más simple.

### S2. Perder evidencia por `\u0000` en jsonb
`FeTransmisionRecorder.php:79`. Además de B1, un NUL hace que se pierda el registro entero. Conviene limpiar `\u0000` del JSON antes de guardar (`str_replace('\u0000', '', $json)` sobre el string codificado, antes de la redacción). Así la evidencia sobrevive.

### S3. Append-only: la protección es solo de modelo, no de la base
`app/Modules/AdminCont/Models/AdminContFeTransmision.php:39-43`. `updating`/`deleting` cubren `->save()`, `->update()`, `->delete()` e `->increment()` sobre una instancia. **No** cubren `AdminContFeTransmision::query()->update()/delete()`, `DB::table()`, `truncate()` ni psql. Para un log interno de soporte es proporcional. Pero la spec lo llama "evidencia fiscal", y el proyecto ya subió `audit_logs` a inmutabilidad a nivel de Postgres (REQ-634). Si se quiere el mismo estándar: un trigger `BEFORE UPDATE OR DELETE ... RAISE EXCEPTION`, o `REVOKE UPDATE, DELETE` al rol de la app, en esta misma migración. Decisión del Arquitecto; no bloquea.

### S4. Colisión de timestamp de migración
`database/migrations/tenant/2026_09_29_130000_create_admin_contab_fe_transmisiones_table.php` comparte prefijo con `2026_09_29_130000_create_ventas_diseno_catalog_pdf_exports_table.php`, ya commiteada en `dev` (91832d4f). Laravel ordena por nombre completo y no depende una de la otra, así que funciona. Como todavía no se commiteó, renombrar a `2026_09_29_130100_...` cuesta cero y evita ambigüedad.

### S5. Digifact: el registro queda casi vacío y guarda el XML firmado
`app/Modules/AdminCont/FacturacionElectronica/Adapters/DigifactPacAdapter.php:148-180`. El DTO nuevo es compatible (campos al final, con default, y todos los `new FiscalDocumentResult(` usan named args). Pero Digifact no completa `requestPayload`, `httpStatus`, `endpoint` ni `ambiente`. Además su XML firmado viaja en `responseData1`, no en `xmlFirmado`, así que el recorder (`:41`, `unset($body['xmlFirmado'])`) lo guarda completo hasta 64 KB. Tampoco setea `errorTransporte`, así que un circuito abierto queda como `pendiente` y no como `sin_respuesta`. La decisión MSL vs Digifact sigue pendiente del cliente: dejarlo como ticket de seguimiento, o quitar también `responseData1`/`ResponseData1` en el recorder ya mismo (1 línea).

### S6. Falta el caso NC real de punta a punta
`tests/Feature/AdminCont/FacturacionElectronica/FeTransmisionesTest.php:246-265`. El test de NC inserta el registro a mano y solo prueba el endpoint. No hay test de que `certificarNotaCredito()` (o `CreditNoteService::decide()` al aprobar) deje un registro con `documento_tipo = 'nota_credito'`, `endpoint = '/v1/notas-credito'` (o el que corresponda) y `documento_id` = id de la NC. El criterio de aceptación 1 pide "factura y NC". Por lectura, el valor sale bien de `FiscalDocumentBuilder.php:80` (`'nota_credito'`), pero hoy nada lo fija.

### S7. Visor: el indicador "Cargando" nunca se ve y hay doble fetch
`atlanticerp-frontend/src/components/admin-contab/FeTransmisionesPanel.tsx:24-37`. `setExpanded` se ejecuta después del `await`, y el bloque de carga solo se renderiza con `expanded`. Resultado: durante la carga no se ve nada, y un segundo clic mientras carga dispara otro `onLoad`. **Fix:** `setExpanded(true)` antes del `await`, y cortar si `loading`. Además, `items` queda cacheado para siempre: tras una aprobación o un polling nuevo hay que cerrar y reabrir el modal. Se puede aceptar, o recargar en cada expansión (la llamada es barata).

### S8. Detalles menores de UI
- `FeTransmisionesPanel.tsx:73`: `toLocaleString()` sin locale ni `timeZone`. El proyecto migró las fechas a America/Panama (SCRUM-883). Para evidencia que se pega en un correo al proveedor conviene fijar `timeZone: 'America/Panama'` o mostrar el ISO.
- `FeTransmisionesPanel.tsx:43`: el `setTimeout` no se limpia al desmontar. Es inofensivo en React 18.
- `InvoiceDetailModal.tsx:117`: el visor exige `estado === 'facturada'`, así que una factura **anulada** que falló la certificación (el caso típico de las pruebas que pidió MSL) no muestra sus transmisiones, aunque el endpoint sí las sirve (`resolveInvoice` con `incluirAnuladas`). Evaluar habilitarlo también para `anulada`.
- No hay test de `InvoiceDetailModal` que cubra el gating del visor (solo existe para la NC).

### S9. `operacion` deducida de `origen`
`FeTransmisionRecorder.php:48`. Es correcta con los 2 callers actuales (certificación → `certificar`, polling → `consultar_estado`), pero acopla 2 conceptos. Si aparece un reintento manual o una consulta de estado disparada a mano, va a mentir. Recomiendo pasarla explícita o derivarla del `endpoint`. Es menor.

### S10. Los topes 64 KB y 50 son técnicos, no umbrales de negocio: bien como constantes
`FeTransmisionRecorder::MAX_BYTES` y `AdminContFeTransmisionController::LIMITE` (`:21`) protegen el almacenamiento y el tamaño de la respuesta HTTP. No representan ninguna regla comercial (margen, porcentaje, monto) que Mark o superadmin necesiten ajustar sin deploy. No aplica la regla de tabla paramétrica. Dos notas:
- el tope es sobre el JSON antes del cast `array`. El jsonb final puede superar 64 KB, porque el cast vuelve a codificar `contenido_parcial` escapando comillas y no-ASCII. Es aceptable, pero conviene decirlo en el docblock.
- con más de 50 registros la UI no avisa que hay más. Con 20 polls como máximo por documento es improbable.

---

## Well done
- **Rutas:** las 2 rutas están dentro del grupo `Route::middleware('primary_approver_only')` (`routes/admin-contab.php:40-41`), con `whereNumber`. No chocan con los grupos `invoices` y `notas-credito` del rol estándar, que no tienen comodines de 2 segmentos. `primary_approver_only` es fail-closed (401 sin JWT, 403 si no hay approver configurado) y deja pasar a superadmin, igual que `actorEsMarkOSuperadmin()` en los flags. `resolveInvoice()` acota `invoice_id` a las facturas del pedido.
- **Sin fuga por serializadores:** `AdminContFeTransmision` solo se referencia desde el recorder, el controller propio y los 2 flags. El modelo no usa `LogsAuditTrail`, y los triggers de SCRUM-916 solo cubren tablas de `auth`, así que la PII no se replica en `audit_logs` ni en `db_audit_log`. Los flags se agregan en los controllers y no en `InvoiceService::detail()`/`CreditNoteService::show()`, que también consumen Bodega y el correo.
- **Redacción antes de truncar,** con `mb_strcut` (corte seguro por bytes sobre UTF-8), `JSON_INVALID_UTF8_SUBSTITUTE` y `xmlFirmado` eliminado. Hay test para la API key, el truncado y el XML.
- **Polling:** registra inmediatamente después de `consultarEstado()`, antes de las ramas `pendiente` (throw) y `errorConfiguracion` (return). Ninguna consulta real queda sin registro. Las salidas tempranas (tenant o documento inexistente) no transmiten nada, así que está bien que no registren.
- **Desvío de la spec bien resuelto:** la spec decía "solo `persistirResultado()` escribe". Registrar en `certificar()` y en el job es mejor, porque así la rama `error_configuracion` del polling (que nunca llega a `persistirResultado`) sí deja constancia.
- **DTO retrocompatible:** campos nuevos al final, opcionales, y todos los constructores usan named args (MSL y Digifact).
- **`apagado` no registra nada** (test), y la certificación sigue siendo incondicional respecto del log (test de fallo).
- **Duplicados:** listener + recertificación dejan 2 registros, y es lo correcto: el log es por **intento**, no por documento. No hay que deduplicar.
- **Migración:** `TenantSchemaMigration`, jsonb, índice compuesto `(documento_tipo, documento_id)` que coincide con la única consulta, `down()` reversible y sin FK física, igual que las referencias sueltas del módulo.
- **Frontend:** todo se renderiza como texto dentro de `<pre>`, con test anti-XSS. No hay precarga: se usa una mutation disparada al expandir. El montaje exige flag y `fe_estado` distinto de `null`/`no_aplica`. i18n completo en es/en, sin emojis, con iconos del set propio. Copiar incluye un fallback silencioso.

---

## Para cerrar
Corregir B1 y B2 (y, si se puede en la misma pasada, S1, S2, S4 y S6), correr `infra/test.sh --filter='FeTransmisiones|AdminContCreditNoteControllerTest'` y el vitest del panel, y volver a pasar por esta review solo sobre ese diff antes de Pre-QA.

---

## Re-review (2026-09-29, solo el diff de correcciones)

### Decision: APPROVED, puede pasar a Pre-QA

Lectura del código corregido y de los tests nuevos. No se volvieron a correr las suites; se usó la evidencia del coordinador: PHPStan L8 limpio, backend 4061 tests con 0 fallos, vitest 2093/2093, tsc limpio.

| Hallazgo | Estado | Verificación |
|---|---|---|
| B1 PII en el log | Cerrado | `FeTransmisionRecorder.php:69-74`: solo registra `exception_class` y `codigo`, nunca `getMessage()`. El test `test_un_fallo_real_de_sql_no_loguea_la_pii_del_receptor_ni_aborta_la_transaccion` provoca una QueryException real (varchar(20) desbordado) y comprueba con `Log::spy` que el contexto no contiene RUC, nombre ni correo. |
| B2 visor al aprobar la NC | Cerrado | `decision()` agrega `puede_ver_fe_transmisiones = true`. La aserción está en `AdminContCreditNoteControllerTest.php:908`. |
| S1 savepoint | Cerrado | `DB::transaction(fn () => ...)` en `:51`. `FeatureTestCase` usa `RefreshDatabase`, que envuelve cada test en una transacción, así que el mismo test ejercita el savepoint de verdad: después del error se puede consultar y guardar `fe_estado`. |
| S2 NUL | Cerrado (con una nota menor, ver abajo) | `:91` y el test `test_un_byte_nul_en_la_respuesta_no_pierde_el_registro`. |
| S4 timestamp | Cerrado | `2026_09_29_130100_create_admin_contab_fe_transmisiones_table.php`. |
| S5 `responseData1` | Cerrado | `:49` y su test. El resto de Digifact queda como ticket de seguimiento, que es razonable porque no está wireado. |
| S6 NC real | Cerrado | `test_una_nota_de_credito_certificada_de_verdad_deja_su_transmision_y_el_endpoint_la_devuelve`. |
| S7 panel | Cerrado | `FeTransmisionesPanel.tsx:28-44`: se expande antes del `await`, hay guard mientras carga y recarga en cada expansión. |
| S8 fecha y anuladas | Cerrado | `formatDateTimeShort` usa `es-PA` y `America/Panama`. El visor se muestra también en facturas `anulada`. `InvoiceDetailModal.test.tsx` cubre el gating. |
| S3 / S9 | No aplicados, a propósito | S3 queda a decisión del Arquitecto. S9 es correcto con los 2 callers actuales. Ninguno bloquea. |

### Hallazgo nuevo (Suggestion, no bloquea)
- `FeTransmisionRecorder.php:91`: `str_replace('\\u0000', '', $json)` trabaja sobre el JSON ya codificado. Si el texto original contiene una barra invertida literal seguida de `u0000` (en el JSON: `\\u0000`), el reemplazo borra la secuencia desde la segunda barra y deja un `\` suelto. Ese JSON es inválido, `json_decode` devuelve null y el `?? []` guarda un registro vacío sin avisar. Es muy improbable en datos del PAC. Solución robusta: quitar `"\0"` de los strings antes de codificar (`array_walk_recursive` con `str_replace("\0", '', $v)`), o que `?? []` loguee un aviso en vez de vaciar sin avisar. Puede ir en un ticket de seguimiento.
