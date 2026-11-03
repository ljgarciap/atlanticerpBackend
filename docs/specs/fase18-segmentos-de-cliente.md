# Fase 18 — Segmentos de cliente como catálogo por empresa

**Estado: APROBADA e implementada (2026-11-03).** Decisiones de Luis: clave interna `b_architect_designer` se conserva; etiquetas por omisión «A · Visita directa», «B · Prescriptor», «C · Licitación», «D · Referido»; implementar ya. Origen: Senior Review de 17b/17c (hallazgo 1) y respuesta de Luis (2026-11-03): el segmento «B · Arquitecto o Diseñador» pasa a un catálogo editable por empresa.

## 1. Problema
`sub_clients.category` (el segmento: A · WalkIn, B · Arquitecto o Diseñador, C · Licitación, D · Referido) es una **lista fija** en 5 sitios: un `enum` de BD, `Rule::in([...])` en 4 Form Requests, y las etiquetas de i18n (es/en). Nombra oficios y canales de un sector concreto y no se puede cambiar sin deploy. La lógica **nunca pregunta por un segmento concreto** (solo filtra y lista), así que es vocabulario de la empresa, igual que las categorías de producto (Fase 9a).

## 2. Diseño (mismo patrón que `product_category`, Fase 9a)
1. **Dominio `client_segment`** en `config/catalogs.php`: `usage => [SubClient::class, 'category']` (una entrada en uso no se borra), sin propiedades. Etiqueta y orden editables en Ajustes → Catálogos.
2. **Migración** (tenant): quita el `enum` (queda `string`, nullable) y siembra las 4 entradas de **sistema** con las claves actuales — así los datos existentes no se tocan —:
   | Clave (no cambia) | Etiqueta por omisión (neutra) |
   |---|---|
   | `a_walkin` | A · Visita directa |
   | `b_architect_designer` | B · Prescriptor |
   | `c_bidding` | C · Licitación |
   | `d_referred` | D · Referido |
   La etiqueta ya no nombra el oficio; cada empresa la renombra. Una empresa con datos conserva sus textos: la migración **no pisa** una etiqueta ya editada.
3. **Validación**: los 4 Form Requests validan con `CatalogService::keys('client_segment')` en lugar de la lista fija (acepta segmentos propios y rechaza los desactivados).
4. **Frontend**: el selector de segmento (alta de cliente, detalle, filtro de la lista de clientes) lee el catálogo (`useCatalogOptions('client_segment')`) en lugar de las 4 constantes de i18n; las etiquetas `category.*` salen de i18n. Un segmento desactivado con clientes se muestra con su etiqueta (no la clave cruda).
5. **Claves**: se conservan los identificadores actuales (`b_architect_designer` incluido) por la política «solo lo visible» de la Fase 17; entran como excepción con dueño en los contratos de vocabulario. Renombrar la clave exigiría un `UPDATE` sobre datos de clientes → **decisión abierta §5.1**.

## 3. Fuera de alcance
- El valor del tipo de asociado (`architect`) y el CRM (vocabulario propio, Fase 7c).
- Cambiar qué segmentos «significan» algo (no hay lógica atada a ellos).

## 4. Criterios de aceptación
1. Un segmento propio creado en Ajustes → Catálogos aparece al dar de alta o editar un cliente y en el filtro de la lista; uno desactivado deja de ofrecerse pero los clientes que lo tienen siguen mostrándolo.
2. Los 4 segmentos de sistema no se borran y los datos existentes no cambian de valor.
3. El texto «Arquitecto o Diseñador» deja de ser el predeterminado y desaparece de la excepción del contrato `asociadoVocabulario` (la lista se encoge).
4. La API rechaza (422) un segmento inexistente o desactivado en los 4 caminos.
5. Suites completas verdes, `npm run e2e:flujos` verde y el catálogo `ventas-diseno.md` actualizado.

## 5. Decisiones (resueltas)
1. La clave `b_architect_designer` **no se renombra** (identificador, no texto).
2. Etiquetas por omisión: las de la tabla del §2.

## 6. Plan
Un PR por repo: backend (config + migración + 4 requests + pruebas + contrato) y frontend (selector/filtro con el catálogo + contrato + e2e). Senior Review antes de mezclar; nada se sube sin «sube» y «mezcla #N».
