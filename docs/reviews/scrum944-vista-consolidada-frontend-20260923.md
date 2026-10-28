# Review: SCRUM-944/945 — Vista consolidada de superadmin (frontend)

**Date**: 2026-09-23
**Reviewer**: Senior Reviewer
**Decision**: ✅ Approved

## Summary

Implementa el deliverable "vista consolidada" de la spec (`docs/specs/auditoria-valores-parametricos-superadmin.md`,
repo atlanticerp-backend) en `atlanticerp-frontend`: `GlobalSettingsPage.tsx` nueva, ruta
`/configuracion/global` gateada por `superadmin.all` (mismo patrón que `/auditoria/uso-ia`), e
ítem de Sidebar en el grupo `Configuración` existente. Sigue exactamente el diseño acordado: 5
módulos que ya tenían un componente `*SettingsPanel` reusable (Compras, Pricing, Orders,
Conditions, Reports) se montan sin tocarlos; los otros 4 (Fiscal, Fraude, Bodega, Servicios), que
eran páginas monolíticas, se refactorizaron a `Page` (wrapper trivial) + `*SettingsPanel` (todo el
comportamiento real), mismo patrón ya usado por `ComprasSettingsPage`/`ComprasSettingsPanel`
(SCRUM-861) — comportamiento 100% preservado, confirmado con los tests renombrados de cada una.

## Findings

### 🔴 Blockers (must fix)
Ninguno.

### 🟡 Suggestions (non-blocking)
Ninguna.

### 🟢 Well done
- **Cero re-arquitectura innecesaria** — en vez de construir endpoints de agregación nuevos o
  duplicar formularios, la vista nueva reusa exactamente los mismos hooks/mutations/endpoints que
  ya usa cada pantalla existente. Ningún storage paralelo, ningún riesgo de que un valor quede
  desincronizado entre la vista vieja y la nueva.
- **Fiscal (exclusiva de Aprobador) accesible desde acá sin cambios de backend** — verificado que
  `EnsureIsPrimaryApprover` (middleware del backend) ya trata `superadmin.all` como bypass
  (decisión de Luis, 2026-08-22, documentada en el propio middleware) — un superadmin que no sea
  Aprobador puede ver/editar Fiscal desde `GlobalSettingsPage` sin tocar el gate de esa pantalla.
- **Solo la tab activa monta su panel** (no las 9 a la vez) — evita 9 requests innecesarios por
  visita, con test explícito que verifica que cambiar de tab desmonta el panel anterior.
- **Test de `GlobalSettingsPage` mockea los 9 Panels a propósito** — el docstring del test explica
  por qué (está probando el contenedor de tabs, no la lógica de cada panel, que ya tiene su propia
  suite) — decisión de testing correcta, no ruido.
- **Tests de Sidebar** cubren tanto el caso positivo (superadmin ve el ítem) como 2 negativos
  (permiso de seguridad sin `superadmin.all`, rol de Admin&Cont sin `superadmin.all`) — mismo
  rigor que el resto del archivo.
- **`CompanyProfilePage` (Datos de la Empresa) correctamente excluida** de la vista — es perfil de
  empresa (razón social, logo, ubicaciones), no un valor paramétrico de cálculo/decisión de
  negocio, fuera del alcance de la definición estrecha ya acordada en la spec.

## Gate

- `npx vitest run` (completo, no scopeado): 177 archivos, 1987 tests, todos en verde.
- `npx tsc --noEmit`: sin errores.
- `eslint` no disponible en este entorno local (binario ausente en `node_modules/.bin`, gap
  preexistente del entorno, no introducido por este cambio) — no bloqueante, vitest+tsc son el
  gate real del proyecto.

## Next steps

Ready for QA. Sin cambios de esquema de datos ni de arquitectura de módulos — no requiere paso
adicional por el Arquitecto más allá de la spec ya aprobada. Pendiente: resultado del deliverable
"auditoría" (backend, en curso) antes de cerrar el batch completo de SCRUM-944/945; push a `dev` y
deploy con OK explícito de Luis.
