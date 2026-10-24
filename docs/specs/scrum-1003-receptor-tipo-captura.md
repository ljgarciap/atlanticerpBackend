# Spec — SCRUM-1003: captura de `receptor_tipo` (Consumidor Final, Gobierno, Extranjero)

**Fecha:** 2026-09-29 · **Estado:** DECISIONES TOMADAS por Luis 2026-09-29 — alcance 01 + 02; lista para Backend/Frontend Dev después de SCRUM-1002 · **Rol:** Analista + Arquitecto
**Origen:** hallazgo de SCRUM-943/958: toda factura y NC sale como contribuyente `01`.

## Estado actual (verificado en el código)
- `sub_clients.receptor_tipo` (string(2), nullable) existe desde la migración `2026_09_17_190000`, pero **no está en `$fillable` de `SubClient` ni en ningún request**: nunca se escribe. Todas las filas son NULL.
- `FiscalDocumentBuilder::receptorDeSubClient()`: si `tax_id` está vacío ⇒ `FiscalParty::consumidorFinal()`; si no ⇒ `tipo = receptor_tipo ?? '01'`. Pero **`tax_id` y `tax_id_dv` son obligatorios al crear** (SCRUM-928), así que la rama Consumidor Final es inalcanzable por la UI.
- El PDF de factura se bloquea si `cliente_ruc` está vacío (`AdminContInvoiceController.php:215`, SCRUM-952) ⇒ chocaría con un Consumidor Final.
- `SubClient.category` incluye `a_walkin` ("A · WalkIn"), pista de que existe cliente de mostrador; el sistema hoy no distingue si ese cliente pide factura a su nombre o va como público general.
- Reglas de MSL (`/referencia` §2):

| tipo | Cliente | `ruc` | `dv` | `nombre` | otros |
|---|---|---|---|---|---|
| 01 | Contribuyente (jurídico o natural con RUC) | obligatorio | recomendado | razón social | — |
| 02 | Consumidor final | **no se envía** | — | `CONSUMIDOR FINAL` | — |
| 03 | Gobierno | obligatorio (RUC de la entidad) | recomendado | entidad | — |
| 04 | Extranjero | identificación fiscal foránea en `ruc` | — | obligatorio | `pais` ISO (ej. `US`); el ejemplo usa ITBMS `00` |

- `FiscalParty` ya soporta `pais`, `email` y `direccion`; no hay columna de país en `sub_clients`.

## Decisiones de negocio que necesito de Luis (bloquean el diseño)
- **D1 — ¿Qué clientes facturan como Consumidor Final?** Opciones: (a) **selector explícito por SubCliente** ("Tipo de cliente ante la DGI"), default Contribuyente, sin inferencias — *recomendada*; (b) inferirlo de la categoría `a_walkin` — riesgo: un walk-in puede pedir factura a nombre de una empresa; (c) decidirlo al facturar, por documento.
- **D2 — Nombre en el documento fiscal de Consumidor Final:** MSL pide `CONSUMIDOR FINAL`. ¿El PDF interno conserva el nombre real de la persona o también dice CONSUMIDOR FINAL? (Contabilidad/fiscal debe confirmarlo.)
- **D3 — ¿Atlantic factura a Gobierno y a Extranjero hoy?** Si sí: ¿con qué frecuencia y hay clientes identificados? Si no hay ninguno, propongo construir **solo 01 y 02 ahora** y dejar 03/04 en un ticket posterior (menos código, menos riesgo).
- **D4 — Si hay Extranjero:** ¿cuál es el tratamiento de ITBMS (MSL lo ejemplifica al 0 %)? Es decisión fiscal, no técnica. Requiere además una columna `country_code`.
- **D5 — Datos existentes:** ¿hay que reclasificar clientes ya cargados? Para verlo en prod (solo lectura, lo corre quien tenga acceso):
  `SELECT category, COUNT(*), COUNT(*) FILTER (WHERE tax_id !~ '^[0-9A-Za-z]+(-[0-9A-Za-z]+){1,3}$') AS ruc_dudoso FROM atlantic_ventas_diseno.sub_clients GROUP BY 1;`
  y revisar a mano los `ruc_dudoso` y los `a_walkin`.

## Diseño propuesto (asumiendo D1-a y D3 = solo 01/02 primero)
- **Backend:** agregar `receptor_tipo` a `SubClient::$fillable` y a `StoreSubClientRequest`, `StoreClientSubClientRequest` y `StoreClientRequest` (`nullable|in:01,02[,03,04]`, default `01`). Reglas condicionales: con `02`, `tax_id` y `tax_id_dv` pasan a opcionales y se ignoran; con `01`/`03`, `tax_id` obligatorio y DV según SCRUM-928.
- **Builder:** `receptorDeSubClient()` usa `receptor_tipo`; `02` ⇒ `FiscalParty::consumidorFinal()` sin RUC ni DV (independiente de lo que haya en `tax_id`). El preview y el validador de "faltan datos" no exigen RUC para `02`.
- **PDF de factura:** el bloqueo por RUC vacío (`AdminContInvoiceController.php:215`) no aplica a `02`.
- **Edición de clientes existentes:** reutiliza el `PATCH .../tax-data` de **SCRUM-1002** (mismo permiso angosto y misma regla de bloqueo por documentos certificados). Por eso el orden es 1002 → 1003. Si se construyera antes, 1003 quedaría "solo al crear".
- **Frontend:** selector en `CreateClientModal` y `ClientDetailModal`; con `02` se ocultan RUC/DV. i18n es/en.
- **Si D3 incluye 03/04:** `03` igual que `01` (RUC de la entidad obligatorio); `04` agrega `country_code` (migración aditiva, nullable) y `pais` al `FiscalParty`; el mapper de MSL ya soporta el campo.
- **Backfill:** ninguno automático; la reclasificación de existentes es manual (D5) con auditoría por `LogsAuditTrail`.

## Criterios de aceptación
1. Un SubCliente `02` genera payload `receptor: { tipo: "02", nombre: "CONSUMIDOR FINAL" }` sin `ruc` ni `dv`, y MSL lo acepta en sandbox (probar con un número que sepamos que certifica).
2. Un SubCliente `01` no cambia de comportamiento (test de regresión con el payload actual).
3. La vista previa, el PDF y la validación de datos faltantes son coherentes con el tipo (`02` no exige RUC).
4. El alta con `02` no exige RUC/DV; con `01` sí, como hoy.
5. Tests unitarios del builder por cada tipo construido; PHPStan Level 8, suite y vitest verdes; Pre-QA en dev contra el sandbox (con la salvedad de la numeración de MSL: coordinar con SCRUM-987).

**Esfuerzo:** pequeño-medio con solo 01/02 (~1-1,5 días); +1 día con 03/04. **Dependencias:** D1-D5 y SCRUM-1002 (edición).

## Decisiones tomadas (Luis, 2026-09-29)
- **D1 — Selector explícito por SubCliente** ("Tipo de cliente ante la DGI"), default Contribuyente (01). Sin inferir de la categoría `a_walkin`, y no se decide por documento.
- **D2 — El PDF interno conserva el nombre real del cliente** en Consumidor Final; solo el documento enviado a la DGI/MSL lleva `CONSUMIDOR FINAL`. *Salvedad a confirmar con contabilidad antes de construir el PDF:* la representación impresa fiscal (CAFE) que emite el PAC dirá CONSUMIDOR FINAL; el PDF de AtlanticERP no debe presentarse al cliente como si fuera ese documento fiscal (agregar una leyenda o entregar el CAFE del PAC cuando exista).
- **D3 — Alcance actual: solo Contribuyente (01) y Consumidor Final (02).** Gobierno (03) y Extranjero (04) quedan fuera; se abrirá un ticket cuando se sepa si Atlantic los factura.
- **D4 — No aplica** (sin Extranjero no hay decisión de ITBMS por ahora).
- **D5 — No se reclasifican los clientes existentes:** solo los clientes nuevos, o los que se editen, podrán ser Consumidor Final (la edición depende de SCRUM-1002).
