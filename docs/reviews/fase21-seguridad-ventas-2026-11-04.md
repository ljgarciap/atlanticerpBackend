# Senior Review — Fase 21: seguridad en Ventas & Diseño (2026-11-04)

Revisión hecha en el mismo hilo (no un agente independiente). **Veredicto: B2 aprobada; B1 aprobada solo como regla de código, sin efecto sobre la Contabilidad del demo (ver spec §3). 0 bloqueantes.**

## Observaciones
1. **B1 no cumple aún la decisión de Luis** para la persona de Contabilidad del demo; es un tema de configuración de módulo, no de código. Se dejó la regla de código porque es correcta para quien tenga solo el permiso angosto.
2. Reactivar exige ahora al aprobador primario: si no hay titular configurado, solo el superadmin puede. Es deliberadamente más estricto que los gates permisivos de Bodega.
3. Los motivos no pasan por un catálogo (Luis eligió texto libre con mínimo de 10 caracteres).
4. Las tarjetas perdidas antes de este cambio no tienen motivo (`lost_reason` nulo); la pantalla simplemente no muestra la línea.
5. `PrimaryApprovers` memoriza los suplentes por proceso: los tests que los cambian deben llamar `forget()` (se hace en `PipelineStageTransitionTest`).
