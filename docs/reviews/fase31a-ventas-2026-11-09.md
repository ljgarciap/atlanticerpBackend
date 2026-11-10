# Revisión de seguridad — Fase 31a (Ventas), 2026-11-09

Alcance: ramas de la 31a de ambos repositorios (spec `docs/specs/fase31-ventas-administracion.md`). Revisión propia contra el diff; **0 bloqueantes**.

## Permisos
- No hay permisos nuevos ni migraciones de `security_levels`: todo lo nuevo reutiliza `ventas_diseno.edit` (baja, reemplazo de contacto, historial de la ficha) y `ventas_diseno.pass_to_order` (lista de solicitudes pendientes). Sin riesgo de fuga por nivel compartido.

## Escrituras y lecturas nuevas
| Ruta | Gate | Notas |
|---|---|---|
| `PATCH clients/{id}/active`, `PATCH architects/{id}/active` | `ventas_diseno.edit` | solo `is_active` booleano (422 si falta); nada se borra; auditado por `LogsAuditTrail`; id no numérico → 404 |
| `POST sub-clients/{id}/contacts/{contactId}/replace` | `ventas_diseno.edit` | el contacto viejo se busca **dentro del subcliente de la URL** (404 si no es suyo); crea, traslada las cotizaciones vinculadas y borra en una transacción |
| `GET catalog/{id}/technical-sheet/history` | `ventas_diseno.edit` | enlaces temporales de 15 min, como la ficha vigente |
| `GET home/approvals-pending` (Ventas) | autenticado; contenido filtrado por `ventas_diseno.pass_to_order` | quien no puede ejecutar el pase recibe lista vacía (cubierto por test con el vendedor) |

## Integridad
- Ocultar no borra: clientes y asociados dados de baja conservan su historial, su detalle sigue abierto por id y se reactivan.
- La ficha técnica reemplazada o quitada se conserva en `catalog_technical_sheet_versions` y su archivo ya no se borra del disco. Es un cambio de comportamiento deliberado; el disco crece con cada versión y no hay depuración automática.
- `AlcanceMiasEquipoTest` vigila que toda ruta GET con alcance Mías/Equipo lleve `team.scope`; las excepciones (Pipeline, Pedidos, calendario de Outlook) tienen motivo escrito.
- Migración `2026_11_26_100000`: aditiva y reversible; todo queda activo y sin versiones previas.

## Observaciones (no bloqueantes)
1. Los archivos del historial de fichas se acumulan sin límite ni depuración.
2. Un cliente dado de baja sigue siendo elegible si se conoce su id (cotizaciones y proyectos existentes lo siguen usando a propósito).
3. El reemplazo de contacto y el historial no tienen botón en la interfaz todavía.
4. Un asociado dado de baja sigue vinculado a sus proyectos y cotizaciones existentes.
