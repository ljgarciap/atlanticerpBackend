# Flujo: CRM propio (proyectos, actividades, contactos, documentos, tablero y reportes)

El CRM es un **módulo propio (`crm`, Fase 19)** con su **propio vocabulario** (Fase 7c: etapas `lead/diseno/cotizacion/propuesta/cerrado/perdido`, tipos `diseno/cotizacion/ambos`), distinto del Pipeline de Ventas & Diseño. Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-03 con las cuentas demo de cada rol (`npm run e2e:flujos`, archivo `crm.spec.ts`, 8 pasos).

## Quién ve qué
| Ruta (`/api/crm/…`) | Vendedor | Gerencia | Contabilidad | Líder de Compras | Técnico | Superadmin |
|---|---|---|---|---|---|---|
| `projects`, `contacts`, `dashboard/*`, `reports/monthly`, `directory?type=` | sí | sí | **no** | **no** | **no** | sí |
| `reports/team` | no | sí | no | no | no | sí |
| `settings` (umbrales) | no | no | no | no | no | sí |

[E] **Fase 19:** todo `/api/crm/*` exige `crm.view`, la visibilidad del módulo `crm` por rol. La migración la sembró **copiando la de Ventas & Diseño** (Vendedor y Gerencia con equipo); el superadmin puede conceder el módulo a otros roles o usuarios en Ajustes, sin deploy. Los `crm.read/write/edit/delete` de cada nivel ya no bastan: un Técnico, un Líder de Compras o Contabilidad reciben **403** aunque su nivel los traiga. Las excepciones por usuario de Ventas & Diseño no se copiaron, y el alcance de Gerencia (`gerencia_scope`) no abre el CRM. `directory` exige `type` (422 sin él) [E].

## 1. Proyecto
- **Alta** (`POST projects`): `nombre` y `tipo` (`diseno|cotizacion|ambos`) obligatorios; `etapa` opcional (nace en `lead`); valor, contacto, encargado, developer, arquitecto (texto libre), fecha de entrega, notas y `assignee_ids`. Lo da de alta cualquier usuario del módulo CRM con `crm.write`; sin el módulo (Técnico, Líder de Compras, Contabilidad) → 403 [E].
- **Quién lo ve**: solo quien está **asignado** (`assignee_ids`) o ve al equipo (Gerencia, Contabilidad, superadmin) [E][C]. La autoría por sí sola no da acceso, pero **desde esta fase el creador sin alcance de equipo queda asignado automáticamente** (antes recibía 403 al abrir su propio proyecto y no aparecía en su lista). Gerencia/superadmin que crean sin responsables no se asignan [E].
- **Quién lo edita**: el asignado y quien ve al equipo; otro vendedor, 403 [E].
- **Baja** (`DELETE projects/{id}`): solo `crm.projects.delete`, es decir Gerencia; el dueño y el Técnico reciben 403 [E].

## 2. Etapas (`PATCH projects/{id}/stage {etapa}`)
Valores: `lead`, `diseno`, `cotizacion`, `propuesta`, `cerrado`, `perdido`; otro → 422 [E]. **No hay compuertas ni orden**: de `lead` a `cerrado` directo, a `perdido` sin motivo, y de ahí de vuelta a `lead` sin aprobación [E]. Distinto del Pipeline de Ventas & Diseño, que exige contacto, m², archivos y aprobación.

## 3. Actividades y recordatorios
- **Actividad** (`POST …/activities`): `tipo` (`llamada|email|reunion|whatsapp|visita|otro`) y `fecha` obligatorios; `fecha` puede ser pasada, pero `siguiente` (próximo contacto) debe ser hoy o futura (422) [E]. Guarda quién la registró [E]. Quien no ve el proyecto recibe 403 al leer o escribir [E].
- **Recordatorio** (`POST …/reminders`): `tipo` (incluye `cotizacion` y `diseno`), `fecha`, `nota`; nace `completado: false`; `PATCH …/toggle` lo alterna (completa y reabre) y `DELETE` lo borra (204; inexistente 404) [E].

## 4. Contactos (`/crm/contacts`)
Directorio **global sin dueño**: todos los roles listan los mismos; alta con `nombre` y `tipo` del catálogo `contact_role`, correo/teléfono/empresa/notas opcionales; edición `PUT` [E]. La respuesta de la lista es `{stats, entries}` (no `data`). **Cualquier usuario del CRM borra cualquier contacto, sea o no suyo** (otro vendedor, 204); sin el módulo, 403 [E].

## 5. Documentos del proyecto
- **Subida** (`POST projects/{id}/documents`, multipart): `categoria` obligatoria (`cotizacion|diseno|presentacion|plano|render|ficha|modelo_3d|otro`), solo extensiones permitidas (`pdf, doc, docx, xls, xlsx, csv, ppt, pptx, imágenes, zip, dwg, rvt, obj…`; un `.exe` → 422), zona `private` por omisión [E].
- **Versiones**: subir otra vez la misma categoría crea la **versión 2**; el listado trae solo la vigente y `…/history` todas [E].
- **Descarga**: `GET documents/{id}/url` devuelve una URL firmada; sin acceso al proyecto, 403 [E].
- **Enlace público** (`POST …/documents/{id}/share-links`): `url` `/public/share/<token>`, vigencia 30 días por omisión; sin sesión redirige (302) al archivo firmado; un token falso o **revocado** da 404 [E]. El token **solo se ve al crearlo**; el listado no lo repite [E]. Revocar (`DELETE share-links/{id}`) exige `crm.delete` [E].

## 6. Tablero, reportes, directorio y autocompletar
- **Tablero** (`dashboard/kpis`): proyectos por etapa y por tipo, pipeline activo y cerrado, alertas (entrega próxima, estancados, clientes sin contacto); `dashboard/reminders`: recordatorios pendientes [E].
- **Reporte mensual** (`reports/monthly`): leads nuevos, cerrados, perdidos y tasa de cierre del mes; exportable a CSV (`reports/monthly/export`, permiso `reports.export`) [E]. **Equipo** (`reports/team`): conteos por etapa y valor activo por usuario, solo para quien ve al equipo [E].
- **Directorio** (`directory?type=`): contactos agregados desde los campos de los proyectos (encargado, developer, arquitecto), con totales [E]. **Autocompletar**: valores previos de un campo y usuarios [E].
- **Ajustes** (`settings`, solo superadmin): `entrega_proxima_dias` (3), `pipeline_estancado_dias` (15), `cliente_contacto_obsoleto_dias` (60), frescura (7/21 días) y topes del PDF de catálogo [E].

## Candidatos a cambio o mejora (ninguno implementado)
1. ~~**El creador de un proyecto sin asignarse no puede abrirlo ni verlo**~~ — **resuelto en esta fase** por decisión de Luis: el creador sin alcance de equipo se asigna solo (test de regresión y el test heredado actualizado). [E]
2. ~~**Los permisos `crm.write/edit/delete` llegan a roles sin el módulo**~~ — **resuelto en la Fase 19** (módulo `crm` propio con `crm.view` por rol). [E]
3. **Etapas sin compuertas**: de Lead a Cerrado y de Perdido a Lead sin motivo ni aprobación, y sin relación con el Pipeline de Ventas & Diseño (dos «pipelines» que no se sincronizan). [E]
4. **Contactos globales sin dueño ni auditoría visible**: cualquiera con el CRM edita y borra el de cualquiera. [E]
5. **Dos vocabularios paralelos**: etapas, tipos y roles de contacto del CRM (`arquitecto`, `encargado`…) ya no coinciden con el catálogo de asociados de Ventas & Diseño (Fase 17). [C]
6. **Listas fijas en el código**: tipos de proyecto, tipos de actividad y de recordatorio y categorías de documento (solo los roles de contacto son catálogo). [C]
7. **La lista de contactos responde otra forma** (`{stats, entries}`) que el resto de listados (`{data, meta}`). [E]
8. **El token del enlace público no se recupera** (correcto), pero tampoco hay forma de listar a quién se compartió ni cuántas veces se abrió desde la pantalla de documentos. [E]
