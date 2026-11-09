# Revisión de seguridad — Fase 29b (Compras), 2026-11-08

Alcance: rutas y controladores nuevos de Compras (reemplazo de documentos, edición parcial de proveedores y agencias, edición de familias, condiciones de compra, `status_visible`/`decision`).

| Punto | Resultado |
|---|---|
| Permisos | Todas las rutas nuevas exigen `compras.edit` (editar/reemplazar/desactivar/condiciones); `accept-substitute` y `keep-original` repiten el middleware de approve/reject; ninguna abre acceso a roles nuevos. Hay pruebas de 403 para el vendedor en agencias, proveedores, familias, condiciones y reemplazo. |
| Pisarse entre usuarios | El control de versión de proveedores es opcional y devuelve 409 sin escribir; el `PUT` completo se conserva. |
| Datos | Nada se borra: proveedores, agencias y familias solo se desactivan (`is_active`); el documento reemplazado queda con baja lógica, quién y motivo. |
| Archivos | El reemplazo valida extensión y tamaño igual que la subida; el archivo anterior se conserva en el almacenamiento. La confirmación del proveedor con pagos no se reemplaza. |
| Montos | El historial de envío oculta el costo a quien no ve montos (`OrderMoneyVisibility`). |
| Integridad | Moneda de la familia validada (3 letras); mínimo y múltiplo enteros ≥ 1; solo proveedores ya asociados al producto; todo cambio de condiciones y de familia queda en auditoría. |
| Migraciones | Tres, aditivas y reversibles; sin cambio de comportamiento al desplegar. |

**Bloqueantes: 0.** Sugerencias: auditar también el cambio de datos de agencias; probar el reemplazo de documentos de punta a punta en el navegador.
