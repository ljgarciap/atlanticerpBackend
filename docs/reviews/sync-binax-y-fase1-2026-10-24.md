# Review: sincronización con binax (#1) + Fase 1 limpieza del núcleo (#2) — backend y frontend
**Date**: 2026-10-24
**Reviewer**: Senior Reviewer
**Decision**: ✅ Approved con sugerencias no bloqueantes (4 PR)

## Alcance y método
PR revisados: backend #1 (1849 archivos, +178k) y #2 (363 archivos); frontend #1 (623 archivos) y #2 (321 archivos).
Un diff de este tamaño no se lee línea por línea: la revisión fue **por riesgo**, no exhaustiva.

Revisado a fondo:
- las 5 migraciones de Fase 1 (únicas con efecto destructivo real);
- barrido de secretos sobre todo el diff de la sync;
- migraciones nuevas de la sync (137): `drop*` solo aparecen en `down()` o en tablas propias de la feature;
- cambios en workflows e infra;
- restos de `binax`/`grupolafayette`/`illuminations` y de nombres de personas;
- middleware `role:` restante y referencias huérfanas a módulos retirados.

**No revisado ni re-ejecutado en esta sesión**: la suite completa (~18 min).
- #1 ambos repos: confío en el CI en verde de GitHub.
- #2 ambos repos: el CI no corre (PR apilados, solo corre contra `master`). Los números (PHPStan 0, 6191 backend, 3097 frontend) son de la sesión anterior, no de ésta.
- Sugerencia: correr `infra/test.sh` sobre `fase1/limpieza-nucleo` una vez antes de mezclar #2.

## Summary
La sync es un snapshot mecánico con transformaciones reproducibles (`scripts/upstream-sync/`) y no encontré nada que impida mezclarla.
Fase 1 está bien construida: migraciones con guardas explícitas, permisos de rol completo fuera del catálogo a propósito
(un `extra_permissions` individual no puede concederlos) y chequeo de fuga por nivel compartido, lección de la Fase 2 anterior aplicada.

## Findings

### 🔴 Blockers
Ninguno.

### 🟡 Suggestions (no bloqueantes)
1. **`config/database.php:192`, `.env.example:268`** — `LAFAYETTE_DB_HOST` apunta por defecto a `app.grupolafayette.com`, un host de otra empresa.
   Si alguien activa el módulo Legacy con el `.env.example` copiado, intenta conectarse a esa infra.
   Sugerencia: default vacío/`null` y fallar claro si falta; se resuelve naturalmente en la Fase 5.
2. **`ReportsController::comisionCarlosVergara`, ruta `/comision-carlos-vergara`, tipos/componentes del frontend** — nombre de persona en una URL de API y en identificadores (~18 ocurrencias en frontend, 5 en backend).
   Esto escapó a `depersonalize.py`, que cubrió datos y mensajes pero no identificadores de código.
   El gate en sí es correcto (permiso `...commission.view_own`, no un ID hardcodeado).
   Aparte, el técnico se resuelve con `InternalTechnician::where('has_bonus_plan', true)->first()`: supone **uno solo** con plan de bonificación; con dos, el reporte devuelve uno arbitrario.
   Sugerencia: renombrar a `comision-tecnico` y decidir la semántica de "el técnico" (el propio usuario) en la Fase 3/4.
3. **Migraciones `100000` Lumo y `100001` Biblioteca** — irreversibles en datos (`down()` solo restaura la fila del catálogo).
   Está documentado en el docblock y es aceptable por la decisión de Luis; solo confirmar que no hay un entorno con esos permisos concedidos que importe conservar. `100002` (CotizaYA) aborta si hay datos, correcto.
4. **`200000_role_gates_to_permissions`** — lanza excepción si el nivel de `management`/`lider_compras` lo comparte otro rol.
   Correcto como guarda, pero en un entorno con datos modificados a mano abortaría todo el `migrate` de tenants. Basta saberlo antes del deploy.
5. **Deploy** — mezclar a `master` dispara `deploy-prod` (falla limpio sin secretos; Luis lo aceptó).
   Se recomienda aun así `if: false` en esos jobs hasta que haya infraestructura.
6. **Comentarios de infra** — `docker-compose*.yml` mencionan `binax` de forma intencional (advertencia de colisión de proyecto Compose). No tocar.
7. **Gate local (`infra/gate.sh`, `.githooks/pre-push`)** — viene en el repo pero inactivo (`core.hooksPath` sin configurar); decisión de flujo, no de código.

### 🟢 Well done
- Sin secretos reales en el diff (solo claves falsas de tests).
- Fase 1: el chequeo anti-fuga de niveles compartidos es código ejecutable, no solo documentación.
- Binax intacto; la herramienta de sync es reutilizable y está documentada.
- CI de #1 en verde en ambos repos.

## Next steps
- Orden de merge: backend #1 y frontend #1 → reorientar #2 a `master` → (idealmente) correr `infra/test.sh` sobre Fase 1 → merge #2.
- Pendiente de decisión de Luis: merge (hoy bloqueado por el sistema de permisos «merge sin revisión»; este informe es esa revisión).
- Después: spec de sub-fase 1b (frontend del bloque D: ~80 usos de `RequireRole` aún presentes), luego Fase 2 (Fase E del ADR-006).
