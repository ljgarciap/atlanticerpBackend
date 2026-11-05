# Fase 25 — CRM: listas propias, forma de los listados y seguimiento de enlaces

Estado: **implementada (2026-11-05)**. Origen: candidatos 6, 7 y 8 de `docs/flujos/crm.md`.
Decisiones de Luis: se hacen las tres; los valores que ya existen en los datos se conservan como entradas del catálogo; una sola entrega por repositorio.

## 1. Alcance
1. **Listas propias (candidato 6).** Cuatro listas que estaban fijas en el código pasan a catálogos editables en Ajustes → Catálogos (superadmin), con las mismas claves de siempre:
   `crm_project_type` (diseño, cotización, ambos), `crm_activity_type` (llamada, email, reunión, WhatsApp, visita, otro), `crm_reminder_type` (los anteriores + cotización y diseño) y `crm_document_category` (cotización, diseño, presentación, plano, render, ficha, modelo 3D, otro).
   Ninguna es «de sistema»: la lógica no pregunta por una clave concreta (solo valida, lista, filtra y cuenta). Una entrada en uso no se borra (se desactiva).
2. **Forma de los listados (candidato 7).** `GET /api/crm/contacts` responde `{data, meta}` (antes `{stats, entries}`); `meta` trae los totales que antes iban en `stats`. El directorio (`/crm/directory`) conserva su forma `{stats, entries}`: se arma desde los proyectos y no es el listado de contactos.
3. **Seguimiento de enlaces (candidato 8).** El enlace público guarda a quién se compartió (`destinatario`, texto libre opcional, máx. 120). El listado ya traía aperturas y última apertura; ahora el modal de «Compartir» las muestra junto al destinatario.

## 2. Cambios
- Migración `2026_11_19_100000`: siembra los cuatro dominios con etiquetas en español e inglés y cualquier valor ya guardado que no sea de los históricos; **quita los CHECK** que fijaban los valores en la base (`projects.tipo`, `project_activities.tipo`, `project_documents.categoria`) y `contacts.tipo`, que la Fase 14c dejó puesto aunque el catálogo ya admitía roles propios (crear un rol propio de contacto fallaba en la base). `down()` borra las entradas y restaura los CHECK solo si todo lo guardado es de los valores originales.
- Migración `2026_11_19_100001`: `share_links.destinatario` (nullable).
- Validación de los formularios de proyecto, actividad, recordatorio y documento contra el catálogo (`CatalogService::keys`). El tablero cuenta proyectos por los tipos del catálogo (los activos salen en cero) y el reporte mensual cuenta actividades por los tipos del catálogo (uno desactivado o desaparecido con datos se cuenta igual).
- Frontend: hook `useCrmList` (catálogo con respaldo a los valores históricos traducidos), tipos de proyecto/actividad/recordatorio/categoría de documento como texto libre, y modal de enlaces con «Compartido con», aperturas y última apertura.

## 3. Criterios de aceptación (cubiertos)
1. Los valores históricos siguen existiendo con las mismas claves; ningún dato cambia.
2. Un tipo/categoría propio creado en Ajustes se acepta en el formulario; uno inexistente responde 422.
3. Una entrada en uso no se borra; el tablero y el reporte la cuentan.
4. `GET /crm/contacts` responde `{data, meta}`.
5. El enlace guarda el destinatario (recortado, ≤120) y el listado muestra aperturas y última apertura.

## 4. Resultado
Backend: `CrmFase25Test` (7 tests) y 24 tests de contactos actualizados a la forma nueva. Frontend: `ShareLinkModal.test.tsx` y `ActivityTimeline.test.tsx` (6 tests); e2e `crm` con paso nuevo 7b (9 pasos).
**Abiertos**: el color e ícono de un tipo de actividad propio es el neutro (los de sistema conservan los suyos); el directorio sigue con su forma propia; los tipos y roles de contacto siguen sin unificarse con el catálogo de asociados de Ventas & Diseño; la IA que redacta mensajes (email/WhatsApp/llamada) conserva su lista propia (no es una lista del CRM).
