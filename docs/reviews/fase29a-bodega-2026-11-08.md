# Revisión de seguridad — Fase 29a (Bodega)

Fecha: 2026-11-08. Alcance: grupos 1 a 3 de `docs/specs/fase29-bodega-compras.md` (backend y frontend). Resultado: **aprobada, 0 bloqueantes**.

## Superficie revisada
| Cambio | Riesgo mirado | Veredicto |
|---|---|---|
| `AprobacionGate`: el rol Gerencia decide ajustes, reubicaciones y conteos | Ampliar quién aprueba. Es decisión de Luis (2026-11-08). Las rutas siguen exigiendo `bodega.approve`; las órdenes de compra NO se tocan (siguen exclusivas del aprobador primario). | Correcto. Sugerencia: si Gerencia solicita un ajuste, aprobarlo ella misma es posible (hoy Gerencia no solicita). |
| `PATCH reject-amount-change` pide `motivo` | El gate de identidad va antes de validar el motivo (un no-aprobador recibe 403, no 422). El motivo queda en la auditoría (`compras.amount_change.rejected`). | Correcto. |
| Permiso `bodega.locations.manage` (migración `2026_11_23_100000`) | Fuga por nivel compartido (lección de la Fase 2). El Líder de Bodega tiene nivel propio (se verifica y aborta si no). El Asistente compartía nivel con Auxiliar y Transporte: recibe un nivel nuevo clonado, y solo se mueven sus usuarios que estaban en el nivel de origen. Las rutas exigen `bodega.write` **y** el permiso nuevo. | Correcto. Aviso: un usuario con un nivel individual igual al del Líder de Bodega (p. ej. «Power User», nivel 4) hereda el permiso, como con cualquier permiso de ese nivel. |
| Kardex con `producto_id`, `desde`, `hasta` | Inyección y datos de más. Validados (`integer`, `date`, `after_or_equal`); consultas parametrizadas; el permiso de lectura no cambia. | Correcto. |
| Ruta del día con atrasados | Muestra más pedidos a quien ya leía la ruta (`bodega.read`). Sin datos nuevos sensibles. | Correcto. |
| Mapeo: validación de roles, `DELETE`, `reassign-pending` | Solo Líder o superadmin (mismo gate que el alta). `reassign-pending` acota por vendedor y excluye entregados; no toca pedidos de otros vendedores. El `DELETE` desactiva, no borra. | Correcto. |

## Hallazgos
Ninguno bloqueante ni importante. Sugerencias abiertas: (1) auditar `warehouse-assistant-mapping` (alta, baja y reasignación) como se hace con los aprobadores de la Fase 28; (2) la interfaz del mapeo no existe (el mapeo es solo API): decidir dónde vive.
