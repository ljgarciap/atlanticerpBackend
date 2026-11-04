# Senior Review — Fase 21: seguridad en Ventas & Diseño (2026-11-04)

Revisión hecha en el mismo hilo (no un agente independiente). **Veredicto: aprobada, 0 bloqueantes.**

## Observaciones
1. **B1**: además de la regla de código, se retiró de los seeders de demo la visibilidad de módulo que daba a Contabilidad Ventas completo; los tests heredados la reciben desde `TestDatabaseSeeder`. Contabilidad perdió también catálogo, calendario, reportes, inicio, proyectos y las familias de Compras (decisión de Luis: solo crear y vincular); conserva sus datos fiscales de clientes y la exención de ITBMS.
2. Reactivar exige ahora al aprobador primario: si no hay titular configurado, solo el superadmin puede. Es deliberadamente más estricto que los gates permisivos de Bodega.
3. Los motivos no pasan por un catálogo (Luis eligió texto libre con mínimo de 10 caracteres).
4. Las tarjetas perdidas antes de este cambio no tienen motivo (`lost_reason` nulo); la pantalla simplemente no muestra la línea.
5. `PrimaryApprovers` memoriza los suplentes por proceso: los tests que los cambian deben llamar `forget()` (se hace en `PipelineStageTransitionTest`).
