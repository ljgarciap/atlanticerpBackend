# Fase 23 — Ajustes de Ventas & Diseño (candidatos 9, 10, 8, 7 y 2)

Estado: **implementada** (decisiones de Luis: alcance completo de los cinco candidatos; Gerencia **ve y edita** los ajustes).
Origen: candidatos abiertos de `docs/flujos/ventas-diseno.md`, siguiente en el orden recomendado tras la Fase 22.

## 1. Qué cambia

| # | Candidato | Resultado |
|---|---|---|
| 9 | Ajustes solo del superadmin | Gerencia ve y edita precios, margen de Pedidos, tramos de comisión y metas. Los permisos `ventas_diseno.pricing.configure` y `ventas_diseno.reports.configure` viven en el **nivel 9** (exclusivo de `management`, sin riesgo de fuga a otros roles). Los tramos (`sales-commission-tiers`) pasan de `superadmin.all` a `reports.configure`. Pantalla nueva «Ajustes» en el módulo (`/ventas-diseno/ajustes`), con una pestaña por permiso. |
| 10 | Nadie paga la comisión de vendedores | `ventas_diseno.commissions.mark_paid` también en el nivel 9. |
| 8 | `scope=team` se degradaba en silencio | Middleware `team.scope`: pedir equipo sin `canViewTeamV2('ventas_diseno')` responde **403** `team_scope_forbidden`. Aplica a Inicio (`home/summary`), Reportes (`summary`, `commissions`), cotizaciones, proyectos (lista y exportación) y calendario interno. **No** al Pipeline ni a Pedidos: allí el alcance de equipo lo dan también `pipeline.read_all`/`pipeline.edit` y el default de Pedidos es `team`. La pantalla ya ocultaba el selector a quien no ve al equipo. |
| 7 | Ficha técnica la sube cualquiera y no se borra | Subir y borrar exigen `ventas_diseno.edit`; `DELETE /catalog/{id}/technical-sheet` (204; 404 si no hay ficha) limpia las tres columnas y borra el archivo. La descarga sigue con `ventas_diseno.read`. La pantalla del Catálogo oculta subir/quitar a quien no edita. |
| 2 | Se podía borrar el último contacto | `DELETE …/contacts/{id}` responde 422 `last_contact` si es el único del subcliente. |

## 2. Migración

`2026_11_16_100000_grant_ventas_settings_to_management` — mezcla las tres claves en `security_levels.permissions` del nivel 9 (reversible: la `down` las quita). Sin cambios de esquema.

## 3. Decisiones por defecto (revisables)

- «Quitar ficha» es borrado real del archivo (no hay historial de versiones); si el borrado del archivo falla, queda registrado en el log y la fila ya está limpia.
- El 403 de `scope=team` no distingue pantallas: es un contrato de la API, no un mensaje de la pantalla.
- Los permisos finos no se dan a Contabilidad ni a Líder de Compras: la capacidad de rol completo va por nivel y esos niveles se comparten.

## 4. Resultado

Pruebas: `VentasFase23Test` (5), ajustes en la matriz del e2e de Ventas, `AjustesPage.test.tsx` y `CatalogPage.test.tsx` (frontend). Ver el informe en `docs/reviews/fase23-ajustes-ventas-2026-11-04.md`.
