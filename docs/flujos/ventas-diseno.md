# Flujo: Ventas & Diseño (clientes, arquitectos, catálogo, calendario, Inicio y reportes)

Complementa la [cadena venta a cobro](cadena-venta-a-cobro.md), que ya recorre cliente mínimo → cotización → pipeline → pedido. Aquí está lo que esa cadena no toca. Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-03 con las cuentas demo de cada rol (`npm run e2e:flujos`, archivo `ventas-diseno.spec.ts`, 10 pasos).

## Quién ve qué
| Pantalla / API | Vendedor | Gerencia | Contabilidad | Líder de Compras | Técnico | Superadmin |
|---|---|---|---|---|---|---|
| Clientes, arquitectos, catálogo, calendario, Inicio, reportes, comisiones, proyectos | sí | sí | sí | no | no | sí |
| Tablero de Gerencia (`dashboard`) | no | sí | no | no | no | sí |
| Pedidos (`orders`) | no | sí | no | sí | no | sí |
| Auditoría, precios, umbral de pedidos, condiciones, tramos de comisión, metas | no | **no** | no | no | no | sí |

Verificado en 16 rutas × 6 roles [E]. Dos cosas llaman la atención: **Contabilidad tiene todo el acceso de lectura y escritura de Ventas & Diseño** (puede dar de alta clientes y arquitectos) y **Gerencia no ve ningún ajuste** (precios, tramos, metas, auditoría): son exclusivos del superadmin [E][C].

## 1. Clientes
- **Alta** (`POST clients`): exige cliente (`name`), subcliente (`business_name`, `tax_id`, `tax_id_dv`, `receptor_tipo`) y **al menos un contacto**. Es el alta completa; el flujo mínimo de la cadena (`master-clients` + `sub-clients`) no exige contacto [E].
- **No hay control de duplicados**: el mismo RUC en otro cliente se acepta (201) [E].
- Los clientes son **globales**: otro vendedor los lista y los abre [E]. Búsqueda por nombre; el listado trae proyectos activos/perdidos/totales y días desde el último contacto [E]. Un id inexistente o no numérico → 404 [E].
- **Categoría** del subcliente (`PATCH …/category`): lista fija de valores (p. ej. `b_architect_designer`); otro valor → 422 [E].
- **Datos fiscales** (`PATCH …/tax-data`): se reenvían **completos** (razón social y RUC obligatorios); parciales → 422 [E]. `POST validate-tax-id` consulta al proveedor fiscal; sin él responde 200 con `disponible: false` en vez de fallar [E].
- **Directorio fiscal** (`GET clients/fiscal-directory`): lo leen Vendedor, Gerencia y Contabilidad; Compras recibe 403 [E].
- **Contactos del subcliente**: rol solo `client`, `architect` u `other`; exige correo o teléfono; la edición reenvía todos los campos (un `PUT` parcial → 422); borrar → 204. **Se puede borrar el último contacto** aunque el alta exigiera uno [E].

## 2. Arquitectos y asociados
- **Alta** (`POST architects`): nombre, tipo (`architect`, `designer`, …) y correo, teléfono o documento; tipo inválido → 422 [E].
- **Duplicado**: mismo correo, teléfono o documento → **409** `duplicate_exact` con el registro existente (el frontend ofrece usarlo) [E]. Es el control que sí falta en los clientes.
- Edición in-place (`PUT`), filtro por tipo y búsqueda [E]. Quien no ve Ventas & Diseño recibe 403 [E].
- **Asociados de un proyecto** (`POST pipeline/{id}/associates {architect_id}`): asociar dos veces no duplica (201 y la misma lista) [E]. Quitar es `DELETE …/associates/{id}`; se **bloquea** si el asociado es el «arquitecto de comisión» de una cotización del proyecto [C].
- **El detalle del arquitecto no lista los proyectos donde solo es asociado**: se arma con `quotes.architect_id` (el arquitecto de comisión), no con los asociados; un asociado recién agregado aparece con `projects: []` [E][C].

## 3. Tarjeta de pipeline: contactos, «perdido» y quién la toca
- Para **salir de Lead** la tarjeta necesita al menos un contacto propio (los del subcliente no cuentan); si no, 422 «Falta al menos 1 contacto antes de mover esta tarjeta desde Lead» [E].
- **«Perdido»** se alcanza sin motivo y **se deshace** con otro cambio de etapa (`lost` → `lead`), sin aprobación [E].
- `request-pass-to-order` solo vale desde «Aprobado» (422 con el mensaje en otro caso) [E].
- **Quién la edita**: el dueño; **Gerencia** edita la de cualquiera (200); otro vendedor recibe 403 al verla, moverla o editarla; **Contabilidad la ve y también la mueve y edita** (su permiso `pipeline.edit` le da alcance de equipo dentro del Pipeline) [E][C].

## 4. Alcance «Mías / Equipo»
- El vendedor solo ve lo suyo. Pedir `scope=team` sin permiso de equipo **no falla: se reduce a lo propio** [E].
- Gerencia con `scope=team` ve todo; **sin pedirlo ve solo lo suyo** (0 tarjetas en el demo). Contabilidad lee el equipo [E].
- Los reportes lo respetan: `scope: own` (sin `top_vendors` ni `forecast`) para el vendedor, `scope: team` para Gerencia [E]. La lista de vendedores del Inicio (`home/vendors`) es vacía salvo para quien ve al equipo [E].

## 5. Catálogo
- Lista con búsqueda (referencia/nombre, tolerante a tipeo), paginación, y por producto: `disponible`, `stock_aparte`, `en_camino`, precio, familia y si tiene ficha técnica [E]. Id inexistente o no numérico → 404 [E].
- **Familias** (`catalog-product-families`): además de Ventas, las leen Compras y Bodega para su filtro (Compras recibe 200; Técnico 403) [E].
- **PDF** (`POST catalog/send-pdf`): `mode` = `completo` o `seleccionados`; `seleccionados` exige `product_ids`; otro valor o vacío → 422; el resultado es un PDF real (`%PDF-`) [E].
- **Ficha técnica**: subir (`POST …/technical-sheet`, archivo obligatorio) y descargar (URL firmada). **La sube cualquiera que lea el catálogo** y **no existe borrado**; la siguiente subida reemplaza [E].

## 6. Calendario
`GET calendar` lee eventos de **Outlook**. Sin Outlook configurado responde 200 con `data: []` y `source_unavailable: true` (la pantalla queda vacía sin error) [E]. La tabla propia `calendar_events` (CRUD en `calendar-events`) sigue expuesta pero la pantalla ya no la usa; sin `start_at` → 422 [E][C].

## 7. Inicio, tablero, comisiones y proyectos
- **Inicio** (`home/summary`): `final_stage`, `pendientes` (sugerencias por tarjeta: sin contacto, sin arquitecto…), `events_today_count`, `performance`. «Aprobaciones pendientes» responde siempre vacío [E][C].
- **Tablero** (`dashboard/summary`): solo Gerencia; alertas (propuestas vencidas, clientes fríos), tarjetas por etapa, totales y etiquetas. `remind` responde 422 si no hay propuestas vencidas [E].
- **Comisiones del vendedor** (`reports/commissions`): pagado, pendiente y cohortes por mes con porcentaje de tramo. **Marcar una cohorte como pagada exige un permiso que ningún rol del demo trae: Vendedor, Gerencia y Contabilidad reciben 403; solo el superadmin llega** (cohorte inexistente → 404) [E].
- **Proyectos** (`projects`): lista y exportación **CSV** con BOM y columnas `Proyecto, Cliente, Etapa, Etiqueta, Responsable, Valor, Superficie trabajada, Días en etapa, Fecha de entrega, Archivos` [E].

## 8. Ajustes (solo superadmin)
Lectura y validación de: precios (`project_discount_percent` ≤ 100…), tramos de comisión de vendedores (primer tramo desde 0, último abierto, mínimo ≤ máximo), probabilidades del Forecast (≤ 100) y umbral de margen de pedidos [E]. Las probabilidades del Forecast las **lee** cualquiera que vea Reportes, pero editarlas exige permiso (403) [E]. La auditoría registra, entre otras, la creación de tarjetas [E].

## Candidatos a cambio o mejora (ninguno implementado)
1. **Clientes sin control de duplicados** de RUC; los arquitectos sí lo tienen (409). [E]
2. **Se puede borrar el último contacto** de un subcliente aunque el alta lo exija. [E]
3. **Un cliente no se puede dar de baja ni corregir en bloque**: la API no ofrece borrado de clientes, arquitectos, tarjetas ni fichas; los datos de prueba permanecen. [C]
4. **«Perdido» sin motivo y reversible sin aprobación**: no queda por qué se perdió ni quién lo reabrió fuera de la auditoría. [E]
5. **El detalle del arquitecto ignora los asociados**; solo cuenta al «arquitecto de comisión». Puede confundir: un arquitecto asociado a diez proyectos muestra cero. [E][C]
6. **Contabilidad edita y mueve tarjetas de cualquier vendedor** (también a «perdido»); el permiso se concedió para la vinculación mínima de clientes y hoy abarca todo el Pipeline. [E]
7. **La ficha técnica la sube cualquiera** con acceso de lectura y no se puede borrar. [E]
8. **Pedir `scope=team` sin permiso se degrada en silencio** a lo propio; la pantalla no avisa. [E]
9. **Los ajustes de Ventas & Diseño son solo del superadmin**: Gerencia no ve precios, tramos ni metas aunque opere el negocio. [E]
10. **La comisión de vendedores no la paga ningún rol del demo**: `ventas_diseno.commissions.mark_paid` no lo trae ningún rol del demo; hay que concederlo a mano. [E][C]
11. **La pantalla de Calendario depende por completo de Outlook**; sin él queda vacía y el CRUD interno de eventos es código sin uso. [E][C]
12. **«Aprobaciones pendientes» del Inicio está siempre vacía**. [C]
