# SCRUM-1120 — CotizaYa: cotizador de venta chica independiente del flujo comercial

**Ticket:** SCRUM-1120 (reporta Gerencia Test, prioridad Highest, épica SCRUM-327). **Estado:** spec aprobada por Luis (2026-10-07); implementada.
**Decisiones ya tomadas por Luis:** (1) el producto se llama **CotizaYa** — así en el menú, en la pantalla y en el botón superior derecho, que se rotula solo "CotizaYa" (el ticket, releído 2026-10-07, ya trae ese nombre en todos los lugares; su título aún dice "Cotizador", solo es el texto del ticket); (2) numeración con **serie propia**.
**Decisiones de Luis (2026-10-07, tras releer el ticket):** (6) la **Descripción se permite pero NO es obligatoria**; (7) el **Cliente Master no lo elige el usuario**: toda CotizaYa usa el cliente «CotizaYA» que ya existe en producción (id 15089 en prod, con un único subcliente «CotizaYA» id 15091, categoría walk-in, receptor consumidor final; creado 2026-10-07). No existe en dev (verificado, solo lectura).
(8) el formulario suma dos campos de texto libre: **Nombre del cliente** y **Teléfono** (aprobados por Luis, fuera del listado original del ticket); (9) las 6 cotizaciones y 2 tarjetas que ya existen en prod con el cliente «CotizaYA» **no se tocan por ahora**; (10) el folio es **`CotizaYA-AAAA-NNNN`**, consecutivo por año, serie propia; (11) el cliente «CotizaYA» **no puede usarse en el flujo normal de cotización**, solo en CotizaYA (ver §2.5). Supuestos del Arquitecto confirmados por Luis: tras Generar la cotización queda fija (solo detalle y PDF); la tabla la ve igual que Cotizaciones — el vendedor las suyas, Gerencia y quien tenga vista de equipo todas — con los permisos actuales `ventas_diseno.read/write`, sin permiso nuevo.

## 1. Qué es

Una herramienta para generar cotizaciones de venta chica con pocos campos, guardadas SOLO en el submenú CotizaYa (Ventas y Diseño, justo después de Cotizaciones). No crea Lead, ni tarjeta de Pipeline, ni proyecto, ni entra a Cotizaciones, Pedidos, comisiones, reportes ni Gerencia.

## 2. Diseño

### 2.1 Modelo de datos (decisión de arquitectura)
Se reutiliza la tabla `ventas_diseno.quotes` (y sus partidas/ítems/contactos) con una columna nueva `origin` (`comercial` por defecto, `cotizaya`). Razón: el ticket exige reutilizar la lógica de Partidas sin cambios (cantidades, precios, descuentos, motor de márgenes, fotos de ítems, PDF); duplicarla en tablas aparte son ~2.000 renglones de controladores y servicios. Contrapartida: unos 46 archivos leen el modelo `Quote`, por eso el aislamiento es parte central del diseño:
- Global scope Eloquent en `Quote` que excluye `origin = cotizaya` en TODA consulta por defecto; solo el módulo CotizaYa lo levanta de forma explícita (`withoutGlobalScope`).
- Consultas SQL directas a la tabla (hoy `ArchitectCommissionService`) se filtran a mano por `origin = 'comercial'`.
- Una auditoría de los consumidores (Bodega, Admin&Cont, Gerencia, Home, Servicios, reportes, comisiones, comandos QA/limpieza) deja constancia de cuáles tocan `quotes` y cómo quedan aislados; un test por consumidor relevante comprueba que una CotizaYa no se cuenta.
- Migración: `origin` con default `comercial` y NOT NULL (no toca filas existentes) + índice.

### 2.2 Numeración
Contador propio (`quote_folio_counters` no se comparte): tabla/serie nueva análoga a `QuoteFolioCounter`, formato `CotizaYA-AAAA-NNNN`, asignación atómica con `lockForUpdate()` igual que `QuoteFolioService`. No consume ni interrumpe `COT-`.

### 2.2 bis Campos propios
Dos columnas nuevas, nulables, en `quotes`: `cotizaya_customer_name` y `cotizaya_customer_phone` (texto libre, longitud acotada, solo se usan cuando `origin = cotizaya`). El nombre del cliente que muestran la tabla y el PDF es `cotizaya_customer_name` y, si está vacío, «CotizaYA».

### 2.3 Backend
- Endpoints propios `/ventas-diseno/cotizaya` (listado, crear, detalle, PDF), gateados por `ventas_diseno.read/write`; `owner_id` = Diseñador a cargo (mismo alcance Mías/Equipo que Cotizaciones).
- Crear + Generar en un paso ("Generar"): valida los campos propios de CotizaYa, crea la cotización, asigna folio `CotizaYA-`, `generated_at`. NUNCA llama a `confirm()`, nunca crea `SalesProject` ni `PipelineCard`, nunca dispara `QuoteGenerated` hacia listeners del flujo comercial.
- Cliente fijo: el formulario NO tiene selector de Cliente Master; el backend asigna siempre el cliente y subcliente «CotizaYA». Sus ids no se hardcodean (difieren por entorno): una tabla de ajustes de una fila (`cotizaya_settings`: `master_client_id`, `sub_client_id`) los guarda; una migración idempotente la llena buscando el cliente por nombre sin distinguir mayúsculas y, si no existe (dev, test, instalaciones nuevas), lo crea junto con su subcliente con la misma forma que el de producción. Si falta el ajuste o el cliente fue borrado, Generar responde un error claro, nunca crea un cliente por su cuenta en ejecución.
- Validación propia (no la de `validationErrorsForGenerating`, que exige Subcliente, RUC, Proyecto, Contacto y Fecha de entrega): obligatorios Diseñador a cargo y al menos 1 partida con 1 ítem; opcionales Nombre del cliente, Teléfono, Descripción, Contactos y Observaciones. El Cliente Master no se valida porque no se elige.
- Contactos: salen del subcliente «CotizaYA» (los contactos cuelgan de un subcliente); si no tiene, el formulario muestra «Sin contactos registrados».
- Partidas: se reutilizan `QuotePartController`/`QuoteItemController` y el motor de márgenes sin cambios de lógica; esos controladores resuelven la cotización con el scope levantado cuando la ruta es de CotizaYa.
- Totales: Subtotal general, ITBMS y Total con la lógica actual (`Quote::itbms()`, tasa vigente de `ItbmsRateResolver` — hoy 7%, no se hardcodea).
- Condiciones: texto por defecto actual (`DEFAULT_CONDITIONS_TEXT`), editable solo con `ventas_diseno.edit.conditions`, igual que hoy.
- PDF: mismo formato que Cotizaciones (logo, cliente, partidas, subtotales, totales y condiciones en las mismas ubicaciones), por `QuotePdfService` con el folio `CotizaYA-`.

### 2.5 Bloqueo del cliente «CotizaYA» en el flujo normal
El cliente «CotizaYA» es exclusivo de CotizaYA. Mecanismo en el backend (la fuente de verdad), con el frontend solo como ayuda de UX:
- Regla única reutilizable (un servicio `CotizaYaClientGuard` que compara contra `cotizaya_settings`): rechaza con 422 y un mensaje claro cualquier intento de asociar el Cliente Master o el subcliente «CotizaYA» a una cotización comercial, una tarjeta de Pipeline, un proyecto o un subcliente nuevo del flujo normal — al crear y al cambiar el cliente (store/update de cotización, creación/edición de tarjeta, creación de proyecto, preseleccionar por subcliente/duplicar).
- Los listados y buscadores de clientes del flujo normal (selector de Cliente Master/Subcliente de Cotizaciones y Pipeline) dejan de ofrecerlo, para que ni siquiera aparezca.
- Los registros que ya lo usan (6 cotizaciones y 2 tarjetas en prod) siguen funcionando: la regla bloquea ASOCIAR el cliente, no editar un registro que ya lo tiene sin cambiarlo; no se migra ni se modifica ningún dato.
- El módulo CotizaYA es el único camino que lo asigna, y lo hace por el backend, no por entrada del usuario.
- Tests: cada punto de entrada rechaza el cliente en flujo normal (A12) y CotizaYA lo sigue usando.

### 2.4 Frontend
- Ítem de menú "CotizaYa" en Ventas y Diseño, inmediatamente después de "Cotizaciones" (i18n es/en).
- Página CotizaYa: tabla con ID (folio), Folio, Nombre del cliente, Teléfono, Descripción (si la hay), Diseñador, fecha, total, y acciones "Ver detalle" y "Descargar PDF"; buscador y paginación como Cotizaciones.
- Botón superior derecho "CotizaYa" (ese es su rótulo exacto en el ticket, igual que el menú) → formulario con solo: Nombre del cliente (texto libre, opcional), Teléfono (texto libre, opcional), Descripción (opcional), Diseñador a cargo, Contactos (opcional; si no hay: "Sin contactos registrados"), Observaciones (opcional), Partidas (el componente actual `QuotePartCard` reutilizado), Totales y Condiciones; botón "Generar" al final.
- Detalle de solo lectura tras generar.

## 3. Criterios de aceptación

- **A1.** Existe "CotizaYa" en Ventas y Diseño inmediatamente después de "Cotizaciones".
- **A2.** El formulario tiene únicamente Nombre del cliente, Teléfono, Descripción, Diseñador a cargo, Contactos, Observaciones, Partidas, Totales y Condiciones; Nombre del cliente, Teléfono, Descripción, Contactos y Observaciones son opcionales y no hay selector de Cliente Master.
- **A2b.** Toda CotizaYa queda con el Cliente Master y el subcliente «CotizaYA»; el usuario no puede cambiarlos ni mandar otro cliente por la API (se ignora o rechaza).
- **A3.** Partidas funcionan igual que en Cotizaciones (crear partidas, agregar productos, cantidades, precios, descuentos, márgenes).
- **A4.** Subtotal, ITBMS (tasa vigente) y Total se calculan automáticamente igual que hoy.
- **A5.** "Generar" valida obligatorios, crea y guarda la cotización con folio `CotizaYA-AAAA-NNNN` propio; la numeración `COT-` no se altera.
- **A6.** La CotizaYa generada aparece solo en la tabla de CotizaYa; NO aparece en Cotizaciones, Pipeline, Pedidos, Reportes, comisiones, Home ni Gerencia.
- **A7.** No se crea Lead, SalesProject ni PipelineCard; ningún estado del Pipeline cambia.
- **A8.** La tabla permite abrir el detalle y descargar el PDF con el mismo formato que Cotizaciones.
- **A9.** El botón superior derecho, rotulado "CotizaYa", abre el formulario.
- **A10.** Tras Generar no se puede editar (solo consulta y PDF). Un vendedor ve solo las suyas; Gerencia/vista de equipo ve todas.
- **A12.** En el flujo normal de cotización (Cotizaciones, Pipeline, proyectos) el cliente «CotizaYA» no se puede elegir (no aparece en los selectores) ni asignar por la API (422); los registros que ya lo tienen siguen intactos.
- **A13.** Nombre del cliente y Teléfono se guardan, se muestran en la tabla y el detalle, y el PDF muestra el nombre del cliente indicado (o «CotizaYA» si se dejó vacío).
- **A11.** Las cotizaciones comerciales existentes y sus cifras no cambian (tests de regresión de Cotizaciones, Pipeline, Pedidos, Reportes y comisiones intactos).

## 4. Desglose del PM

| # | Tarea | Dueño | Depende |
|---|---|---|---|
| P1 | Migración `origin` + columnas nombre/teléfono + `cotizaya_settings` + contador/serie `CotizaYA-` + global scope + filtro SQL directo en comisiones | Backend | — |
| P1b | `CotizaYaClientGuard` y bloqueo del cliente en el flujo normal (API + selectores) + tests por punto de entrada (A12) | Backend/Frontend | P1 |
| P2 | Auditoría de los 46 consumidores de `Quote` y tests de aislamiento (A6, A7, A11) | Backend | P1 |
| P3 | Endpoints CotizaYa (listado, crear/generar, detalle, PDF) + validación propia + reutilización de partidas/ítems con scope levantado | Backend | P1 |
| P4 | Menú, página/tabla, formulario y detalle CotizaYa (reutiliza `QuotePartCard`) | Frontend | P3 (contrato) |
| P5 | Gate local en ambos repos + Senior Review independiente (seguridad del aislamiento) | Arquitecto | P2–P4 |
| P6 | Pre-QA en dev: que NADA de CotizaYa se cuele en el flujo comercial, PDF, permisos por rol | Pre-QA | P5 |
| P7 | dev → test → prod, cada paso con OK de Luis; Jira y documentación | Arquitecto | P6 |

## 5. Puntos abiertos para Luis

Ninguno bloqueante. A confirmar al aprobar: Nombre del cliente y Teléfono son OPCIONALES (si Nombre del cliente debe ser obligatorio para poder identificar la venta, se cambia en una línea).

## 6. Riesgos

- Mientras el cliente «CotizaYA» siga existiendo en el flujo comercial normal (hay 6 cotizaciones y 2 tarjetas), sus ids no se usan para aislar: el aislamiento es la columna `origin`, no el cliente.
- Es el cambio de mayor superficie de la serie: el riesgo no es la pantalla nueva sino que una CotizaYa se filtre a ventas, comisiones, Bodega o Facturación. Por eso el aislamiento tiene tarea y tests propios (P2) y el Senior Review se enfoca ahí.
- `SalesProject`/`Pipeline` hoy exigen proyecto en varios pasos de Cotizaciones: CotizaYa los evita por completo, no los reutiliza.
- Una CotizaYa no entra a Facturación ni a Bodega; si más adelante se quiere facturar una venta chica, es otro ticket.
