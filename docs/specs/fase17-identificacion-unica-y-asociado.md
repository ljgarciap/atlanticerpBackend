# Fase 17 — Identificación fiscal única por tenant y «Asociado» en vez de «Arquitecto»

**Estado: BORRADOR, pendiente de aprobación de Luis.** Origen: cobertura 11a (`docs/flujos/ventas-diseno.md`, candidatos 1 y 5). Decisiones de Luis (2026-11-03): nombre en pantalla **«Asociado»**; renombre **solo lo visible**; unicidad **normalizada con 409**; la migración **aborta y lista** si hay repetidos.

## 1. Identificación fiscal única (clientes)

### Problema
`ventas_diseno.sub_clients.tax_id` no tiene ninguna restricción: el mismo RUC se acepta en clientes distintos (`POST clients`, `POST sub-clients`, `POST clients/{id}/sub-clients`, `PATCH …/tax-data`). Los proveedores ya rechazan repetidos (comparación `LOWER`, sin normalizar) y los asociados responden 409 `duplicate_exact`.

### Diseño
1. **Clave normalizada** = `tax_id` sin espacios, guiones ni puntos, en mayúsculas, **más el DV** (`tax_id_dv`, vacío si no aplica). Se guarda en una columna nueva `sub_clients.tax_id_key` (calculada por el modelo al guardar) con **índice único**. La columna evita un índice por expresión y permite que el 409 y la BD usen la misma regla.
2. **Una sola regla en el servicio** (`SubClientTaxIdGuard` o similar), llamada por los 4 caminos de alta/edición. Al chocar: **409** `{ code: "duplicate_tax_id", message, existing: { sub_client_id, master_client_id, business_name } }`, igual que `duplicate_exact` de asociados. En edición se excluye el propio subcliente.
3. **Vacío no choca**: el alta exige identificación, pero cualquier fila sin clave (consumidor final u otro caso que la deje nula) no entra al índice (índice parcial `WHERE tax_id_key IS NOT NULL`).
4. **Frontend**: los formularios que crean o editan muestran el 409 con el cliente existente y un botón «Usar el existente» (mismo patrón que asociados); no se reinventa el modal.
5. **Proveedores**: se alinean a la misma normalización (hoy `LOWER` sin quitar guiones). Cambio mínimo en `uniqueTaxIdRule`; **sin** índice nuevo en esta fase (decisión aparte si se quiere).

### Migración
- `2026_11_NN_add_tax_id_key_unique_to_sub_clients`: calcula la clave de las filas existentes y **antes de crear el índice busca repetidos**. Si los hay, **aborta con la lista** (clave, ids y razones sociales) y no toca nada; nunca fusiona ni borra. `down()` quita índice y columna.
- Una base local ya sembrada por los flujos e2e **no la pasa** (117 subclientes comparten el RUC `155-123-4567`): se resiembra con el ciclo habitual `down -v` + `migrate` + `tenant:create` + `db:seed`.

### Fixtures que hay que cambiar (el costo real)
Hoy los e2e y muchos tests reutilizan **un mismo RUC fijo** (`155-123-4567`, DV `12`) para cada cliente que crean:
- Flujos e2e: `apoyo/venta-cobrada.ts`, `venta-a-cobro.spec.ts` y los que creen clientes → RUC derivado del `STAMP` de la corrida (único y repetible).
- Tests del backend: factories/helpers que crean subclientes con el RUC fijo → secuencia única en el factory. Se mide el alcance exacto antes de empezar (4 archivos con el literal; más por factories).
- Seeders demo: los RUC ya son distintos; se verifica.
- **Contratos de neutralidad** no se tocan.

### Etiqueta «RUC»
La Fase 6c ya devuelve la etiqueta del país (`user.fiscal_id.label`: RUC en Panamá, NIT en Colombia, EIN en EE. UU.) y `fiscalIdText()` la aplica en varias pantallas. Quedan textos con «RUC» **fijo** (≈ 40 cadenas en los i18n `es`/`en` y mensajes del backend en `Requests/*`, PDFs y correos). Se reemplazan por `{{fiscalId}}` / la etiqueta del país, con «Identificación fiscal» como texto neutro cuando no hay etiqueta. **No** se renombran columnas ni campos de API (`tax_id`, `ruc` en cotizaciones, perfil fiscal del asociado): son nombres internos (misma política que «solo lo visible»). Panamá no cambia de aspecto donde el texto ya era «RUC».

## 2. «Asociado» en vez de «Arquitecto»

### Alcance (solo lo visible)
- **Cambia**: i18n `es`/`en` (≈ 30 cadenas con «arquitecto/s»: pantalla Arquitectos del menú, filtros, comisiones externas, mensajes de bloqueo), textos de PDF/correo y mensajes de validación del backend, encabezados de Excel y la etiqueta del permiso en el catálogo si dice «arquitecto».
- **No cambia**: tabla `architects`, rutas `/architects`, campos `architect_id`, claves de permiso, nombres de clases/archivos, el valor de tipo `architect`.
- **El tipo «Arquitecto» sigue existiendo** como una opción más del dominio `architect_type` (junto a diseñador, contratista, …); es un dato por empresa, editable en Ajustes → Catálogos. Lo que se renombra es la **entidad** y la pantalla.
- El código ya usa «asociados» para el vínculo con proyectos (SCRUM-1121); esta fase unifica el vocabulario: la persona/empresa que se asocia a un proyecto y puede originar comisión es un **Asociado**; «Comisión externa» se queda.

### Contrato
Se agrega «arquitecto/s» (como palabra suelta en textos visibles) al contrato de neutralidad **con excepciones por dueño que solo pueden encogerse**: el tipo de catálogo `architect` y los nombres internos citados arriba. El contrato falla si reaparece en un texto visible fuera de esas excepciones.

## 3. Criterios de aceptación
1. Crear un segundo cliente con la misma identificación (con o sin guiones/espacios, mismo DV) → 409 `duplicate_tax_id` con el existente; en los 4 caminos de alta/edición. El mismo número con **otro DV** se acepta.
2. Editar un subcliente sin cambiar su identificación no choca consigo mismo.
3. La migración aborta con lista si hay repetidos y no modifica datos; en base limpia crea el índice; `down()` lo revierte.
4. En pantalla, formularios y mensajes dicen la etiqueta del país (RUC en Panamá) o «Identificación fiscal»; no queda «RUC» fijo en un país distinto de Panamá.
5. En pantalla, PDF, correos y Excel no aparece «Arquitecto/s» como nombre de la entidad (sí como tipo de catálogo); API, rutas y permisos intactos.
6. Suites completas verdes (backend con `infra/test.sh`, frontend vitest, `npm run e2e:flujos`); el catálogo `ventas-diseno.md` actualiza los candidatos 1 y 5 a «resueltos» y el paso 2/4 del e2e prueba el 409.

## 4. Plan y orden de entrega
| Entrega | Contenido | PR |
|---|---|---|
| 17a | Unicidad: servicio + migración + 409 + proveedores + fixtures del backend | backend |
| 17a-fe | Manejo del 409 en formularios + fixtures únicos en e2e/vitest | frontend (después del backend) |
| 17b | Etiqueta de identificación por país en textos fijos | backend + frontend |
| 17c | «Asociado»: i18n, PDF/correo/Excel, mensajes, contrato | backend + frontend |

Cada una con Senior Review antes de mezclar. Nada se sube sin «mezcla #N» explícito por PR.

## 5. Riesgos
- **Fixtures**: es la parte grande; un factory mal hecho rompe cientos de tests. Se mide antes de empezar y se corre la suite completa.
- **Datos reales con repetidos** (hoy no hay producción): la migración protege, pero hay que fusionar a mano antes de aplicarla.
- **Normalización**: dos personas distintas con la misma identificación y DV distinto se aceptan (correcto); el mismo número escrito distinto se detecta.
- **Renombre incompleto** de «Arquitecto»: el contrato lo vigila para que no se cuelen textos nuevos.
