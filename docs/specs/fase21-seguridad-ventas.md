# Fase 21 — Seguridad en Ventas & Diseño (B1 y B2)

Estado: **B1 y B2 implementadas**. Nace del orden recomendado tras cerrar la Fase 20 (candidatos 6 y 4 de `docs/flujos/ventas-diseno.md`).

## 1. Decisiones de Luis (2026-11-04)
- **B1**: Contabilidad solo crea y vincula (edita las tarjetas que ella creó); las de otros vendedores quedan en lectura.
- **B2**: «Perdido» exige motivo y reabrir lo decide el aprobador primario o un suplente, con motivo, auditado.

## 2. B2 — implementado
- Migración `2026_11_14_100000` (`pipeline_cards`): `lost_reason/lost_at/lost_by` y `reopened_reason/reopened_at/reopened_by`, nullable (las ya perdidas quedan sin motivo; nunca se inventa uno).
- `PATCH pipeline/{id}/stage`: pasar a `lost` exige `motivo` (10–1000 caracteres); pasar de `lost` a `lead` exige `motivo` **y** que quien lo pide sea el titular del aprobador primario, un suplente (`PrimaryApprovers`) o superadmin (403 si no, con el texto que ya usa la 12b). Sin titular configurado, solo el superadmin. El motivo de la pérdida se conserva al reactivar.
- El detalle de la tarjeta expone `lost_reason`, `lost_at`, `reopened_reason`, `reopened_at` y `can_reactivate`.
- Frontend: formulario de motivo en el detalle (perder y reactivar), botón «Reactivar» solo con `can_reactivate`, el motivo visible en una tarjeta perdida, y el tablero no deja arrastrar hacia o desde «Perdido».

## 3. B1 — implementado (opción elegida por Luis: quitar el módulo y dejar permisos angostos)
- `PipelineCard::isEditableBy`: con **solo** `ventas_diseno.pipeline.edit` (sin `ventas_diseno.edit` ni superadmin) se edita únicamente lo que la persona creó (`created_by`).
- Origen real del problema: el seeder de demo `UserModuleVisibilityGrantSeeder` daba a Contabilidad Ventas & Diseño completo (SCRUM-1150, «replica a Gerencia»: ver, equipo, editar y aprobar) con una visibilidad de módulo propia de su usuario. Se **quita** de ese seeder; Contabilidad queda con permisos angostos (`EXTRA_PERMISSIONS`): `pipeline.read_all`, `pipeline.edit`, `edit.client_tax_data` (corregir datos fiscales de clientes) y `admin_contab.edit.client_itbms_exemption` (eximir de ITBMS), que usa la pantalla «Clientes fiscal» de Admin & Contab.
- Los tests heredados de binax que ejercitan el comportamiento anterior lo siembran en `Tests\Support\TestDatabaseSeeder` (solo pruebas).
- **Efecto para Contabilidad** (verificado): ve todo el Pipeline y crea/edita sus propios proyectos y el alta de clientes por las rutas angostas; ya **no** entra a clientes, asociados, catálogo, calendario, inicio, reportes ni proyectos de Ventas, ni a las familias de Compras, y no mueve tarjetas ajenas. Tampoco pasa proyectos a pedido (`pass_to_order` salía del alcance de equipo): se lo concede un superadmin por permiso adicional si se quiere.
- Un entorno ya sembrado conserva la visibilidad de módulo vieja: hay que borrar esa fila (en el local ya se hizo) o quitarla desde la pantalla de usuarios.

## 4. Verificación
Backend: VentasDiseno + CRM + Bodega 1784 tests en verde, PHPStan 0. Frontend: 248 archivos / 2938 tests, tsc limpio. E2E de flujos: 120 pasos (el paso 5 de Ventas cubre motivo, 403 al dueño y a Gerencia, y la reactivación por el aprobador).
