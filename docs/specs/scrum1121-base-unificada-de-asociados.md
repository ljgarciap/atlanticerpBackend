# SCRUM-1121 — Base unificada de clientes asociados (Arquitectos, Diseñadores y otros) entre Cotizaciones y Pipeline

**Ticket:** SCRUM-1121 (reporta Gerencia Test, prioridad Highest, épica SCRUM-327). **Estado:** spec pendiente de aprobación de Luis (2026-10-08).
**Decisiones de Luis:** (D1) la base única EXTIENDE la tabla de Arquitectos actual (gana tipo y documento y deja de estar atada a un subcliente); los arquitectos existentes y sus comisiones no se tocan; (D2) duplicados: coincidencia exacta de correo, teléfono o documento se BLOQUEA y se ofrece usar el existente; nombre muy parecido solo se AVISA y el usuario decide.

## 1. Situación actual (tres almacenes separados)

| Almacén | Alcance | Uso |
|---|---|---|
| `architects` (nombre, teléfono, correo, `sub_client_id`) | un arquitecto «pertenece» a un subcliente | campo «Arquitecto» de la cotización (`quotes.architect_id`, picker limitado a ese subcliente), pantalla Arquitectos y **comisiones de Admin&Cont** (perfil fiscal, comisión por arquitecto) |
| `pipeline_card_contacts` (rol client/architect/other) | una tarjeta | «Contactos» del detalle del proyecto |
| `sub_client_contacts` + `quote_contacts` | un subcliente | «Contactos» de la cotización |

Un arquitecto o diseñador creado en una pantalla no existe en la otra, y el picker de la cotización solo ve los de su subcliente.

## 2. Diseño

### 2.1 La base (D1)
`architects` pasa a ser la base de **asociados**: columnas nuevas `type` (`architect` por defecto | `designer` | `contractor` | `engineer` | `consultant` | `other`), `document` (identificación, opcional) y `created_by` (opcional). `sub_client_id` se conserva como dato histórico de origen y **deja de acotar la búsqueda**. Todos los arquitectos existentes quedan `type = architect`: ni una fila, ni un id, ni una comisión cambian. No se crea tabla nueva ni se migra ningún dato.

### 2.2 Búsqueda y creación
- `GET /architects?search=&type=`: búsqueda global con autocompletado (tope 20), por nombre, correo, teléfono o documento; el filtro por tipo es opcional. El parámetro `sub_client_id` sigue aceptándose por compatibilidad pero el frontend deja de usarlo.
- `POST /architects` (tipo, nombre, teléfono, correo, documento): antes de crear compara, normalizado (nombre sin acentos/mayúsculas/espacios sobrantes; correo en minúsculas; teléfono solo dígitos; documento sin separadores), contra TODA la base:
  - **Coincide correo, teléfono o documento** (D2): responde 409 con el asociado existente y no crea nada; el frontend ofrece «Usar este».
  - **Nombre igual tras normalizar** (D2): responde 409 con `similar` y solo crea si se reenvía `confirm_similar = true`.
  - Un dato vacío nunca cuenta como coincidencia. Sin umbrales numéricos: es igualdad normalizada.
- `PUT /architects/{id}`: misma verificación de identificadores excluyendo al propio registro.
- Quedan disponibles de inmediato para cualquier cotización o proyecto.

### 2.3 Vínculo con proyecto y cotización: una sola fuente de verdad
El Proyecto (`sales_project`) es el punto común de la tarjeta de Pipeline y de sus cotizaciones, así que los asociados se guardan **a nivel de proyecto**:
- Tabla `sales_project_associates (sales_project_id, architect_id)`, par único.
- Una cotización **con proyecto** lee y escribe los asociados de ese proyecto: lo que se agrega desde la cotización aparece en el detalle del proyecto y viceversa, sin copiar nada.
- Una cotización **sin proyecto todavía** (borrador) guarda los suyos en `quote_associates (quote_id, architect_id)`; en cuanto se le asigna proyecto (incluye crearlo desde la cotización) se funden en el proyecto y se vacía el pivote.
- Endpoints: `POST/DELETE /pipeline/{id}/associates` y `POST/DELETE /quotes/{id}/associates`; los detalles de tarjeta y de cotización devuelven `associates`.
- **El campo «Arquitecto» de la cotización (`quotes.architect_id`) no cambia de significado**: sigue siendo el arquitecto que origina la comisión de Admin&Cont. Su picker pasa a buscar en la base global (tipo arquitecto) y, al elegirlo, además se agrega a los asociados del proyecto. En sentido inverso, agregar un arquitecto al proyecto NO cambia `quotes.architect_id` ni la comisión (ver §5).

### 2.4 Frontend
- Componente único **Asociados** (autocompletado + «Crear nuevo» con tipo, nombre, teléfono, correo y documento + manejo del 409: «Usar este» / «Crear igual») usado en el formulario de cotización y en el detalle del proyecto del Pipeline.
- El picker de Arquitecto de la cotización busca en la base global.
- No toca Clientes Master, Subclientes, ni los «Contactos» (personas del cliente) de la tarjeta y de la cotización. La pantalla Arquitectos y las comisiones de Admin&Cont siguen mostrando solo `type = architect`.

## 3. Criterios de aceptación

- **A1.** Un asociado creado desde una cotización queda en la base y se puede buscar y reutilizar desde otra cotización o desde un proyecto.
- **A2.** Un asociado creado desde el detalle de un proyecto queda en la misma base.
- **A3.** Búsqueda/autocompletado por nombre, correo, teléfono o documento, con todos los tipos disponibles (arquitecto, diseñador, contratista, ingeniero, consultor, otro).
- **A4.** Crear uno nuevo cuando no existe; queda disponible de inmediato.
- **A5.** Creado en una cotización con proyecto → aparece en el detalle del proyecto sin reingresarlo; creado en el proyecto → se puede usar en sus cotizaciones.
- **A6.** Cotización sin proyecto: sus asociados pasan al proyecto cuando se le asigna uno.
- **A7.** Correo, teléfono o documento ya existente → no se crea y se ofrece el existente (409); nombre parecido → aviso con opción de crear igual.
- **A8.** Los arquitectos existentes siguen intactos: mismos ids, mismas comisiones y perfiles fiscales de Admin&Cont, mismo `quotes.architect_id`.
- **A9.** Clientes Master, Subclientes y los «Contactos» de tarjeta y cotización no se modifican.
- **A10.** Las cotizaciones de CotizaYA no participan (sin proyecto ni asociados).
- **A11.** Permisos como hoy: leer/buscar con `ventas_diseno.read`, crear con `.write`, editar con `.edit`.

## 4. Desglose

| # | Tarea | Dueño | Depende |
|---|---|---|---|
| P1 | Migración (`type`, `document`, `created_by`, índices de normalización) + búsqueda global + dedupe en crear/editar + tests (A1–A4, A7, A8) | Backend | — |
| P2 | Pivotes `sales_project_associates` y `quote_associates`, endpoints, fusión al asignar proyecto, `associates` en los detalles + tests (A5, A6, A10) | Backend | P1 |
| P3 | Componente Asociados + picker de Arquitecto global + manejo del 409, en cotización y tarjeta + tests | Frontend | P1, P2 (contrato) |
| P4 | Gate local, Senior Review independiente (foco: no romper comisiones de Admin&Cont), Pre-QA en dev (con pausas) | Arquitecto/Pre-QA | P1–P3 |
| P5 | dev → test → prod (cada paso con tu OK), Jira y documentación | Arquitecto | P4 |

## 5. Riesgos y puntos a tu decisión

- **Comisiones de Admin&Cont:** dependen de `architects` y de `quotes.architect_id`; este diseño no cambia ninguno de los dos. Es la razón de extender la tabla en vez de crear una nueva.
- **Arquitecto agregado en el proyecto y comisión (decisión de negocio, no incluida):** hoy la comisión sale del arquitecto de la cotización. Que un arquitecto agregado solo desde el proyecto genere comisión sería un cambio de negocio aparte; esta spec no lo hace.
- **Tipos fijos:** los seis tipos son una lista cerrada (no hay umbrales ni reglas de negocio variables). Si luego quieren agregar tipos, es un cambio de lista.
- **Duplicados históricos:** la base existente puede tener duplicados ya creados; no se limpian ni se fusionan (no se toca ningún dato).
- **Tamaño:** es un cambio de dos pantallas más modelo de datos; se entrega por partes (P1–P2 backend, P3 frontend) con un solo Pre-QA al final.
