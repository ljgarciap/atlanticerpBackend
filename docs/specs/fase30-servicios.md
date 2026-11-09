# Fase 30 — Servicios

Estado: **APROBADA** por Luis (2026-11-09); decisiones en §3. Origen: `docs/flujos/pendientes-priorizados.md`, Fase 30, y los catálogos `docs/flujos/servicios-{tickets,informe-inspeccion,reclamo-reportes,tecnicos-herramientas}.md`. Una sola fase, un PR por repositorio, con sub-entregas en el orden de §2.

## 1. Verificación previa (2026-11-09)
Contra `master`: las transiciones libres y el ticket cancelado reabrible ya los resolvió la Fase 12a (candidatos 1 y 2 de tickets: tachados). Siguen abiertos, confirmado en el código: el consumo de la Reserva solo en el primer guardado (`InspectionReportService::finishUpsert`, `applyConsumption = isNew`), el archivo adjunto que se borra al guardar como formulario, las fotos sin ruta de borrado, los reportes de carga y comisión abiertos a `servicios.read`, `completados-anio` sin distinguir estados y los insumos sin rutas de edición ni baja. El resto se confirma al implementar cada grupo; lo ya resuelto se tacha en su catálogo.

## 2. Alcance por grupo
| # | Grupo | Qué cambia |
|---|---|---|
| 1 | Tickets | Renombrar en la API `internal_technician_id` a `technician_user_id` (alias, sin romper); compuerta de cierre para instalaciones (según decisión 1); los subtipos de sistema se documentan como contrato. |
| 2 | Informe de inspección | Corregir un material ajusta el Kardex (diferencia, no repetición); guardar como formulario y como archivo no se pisan; fotos con borrar y reemplazar mientras el informe no esté firmado; borrador (según decisión 2). |
| 3 | Hoja de reclamo | Condiciona el cierre del reclamo (según decisión 3); reapertura de una hoja completada con motivo, auditada; borrador; `completados-anio` separa resueltos y cerrados; `tipo_documento` desconocido → 422; visibilidad de carga y comisión (según decisión 4). |
| 4 | Técnicos e insumos | Insumo nuevo con existencia inicial opcional; editar y dar de baja insumos y herramientas (solo desactivar si hay historial); `PATCH` parcial de técnico interno sin borrar lo no reenviado; ficha ligada a un usuario; Gerencia corrige lo que ve; recibir una herramienta exige referencia de compra (según decisión 5). |

## 3. Decisiones de Luis (2026-11-09)
1. **Instalaciones**: para cerrarse exigen informe de inspección completado y al menos una evidencia (foto o archivo).
2. **Informe con borrador**: guardar a medias no cuenta; solo el completado desbloquea el cierre.
3. **Reclamo**: la hoja completada es obligatoria para cerrar, además de la cotización aprobada.
4. **Carga por técnico y comisión**: las ven el Líder de Servicios y Gerencia; el vendedor deja de verlas y el técnico sigue viendo la suya.
5. **Recibir una herramienta** exige una referencia libre (factura u orden de compra como texto), sin cruce automático.

## 4. Criterios de aceptación
Por grupo: tests de permisos por rol y de regresión, catálogo en `docs/flujos/` con candidatos tachados, pasos nuevos en `npm run e2e:flujos`, suite completa verde y revisión de seguridad antes de mezclar. Migraciones aditivas que no cambian el comportamiento al desplegar.
