# Fase 33 — Dependencias con avisos de seguridad

Estado: **33a implementada (2026-11-19); 33b pendiente de decisión de Luis.**

## Origen
La auditoría semanal (`security-audit.yml`) terminaba con código 126 desde hace semanas porque
`infra/audit.sh` perdió el permiso de ejecución (PR backend #144 / frontend #116). Al ejecutarla de
nuevo aparecieron avisos reales.

## 33a — Actualizaciones compatibles (hecho)
| Repo | Paquete | Antes → después | Aviso |
|---|---|---|---|
| Backend | `phpoffice/phpspreadsheet` | 2.4.7 → 2.4.9 | 2 altas (DoS en lector ODS, etc.) y 1 media (SSRF por redirección) |
| Backend | `symfony/yaml` | 7.4.13 → 7.4.23 | 1 alta (recursión sin tope en el lexer) |
| Frontend | `follow-redirects` (vía axios) | 1.16.0 → 1.16.3 | 1 alta |

Solo cambios menores/parches dentro de los rangos de `composer.json`/`package.json`; arrastraron
actualizaciones menores de `symfony/*` y `polyfill-*`. Resultado de la auditoría: backend sin altas
(quedan 1 media y 8 bajas de symfony/laravel); frontend de 2 altas a 1.

## 33b — Cambios mayores (NO hecho, decisión de Luis)
- `zod` 3 → 4 (alta): 19 archivos del frontend lo importan; la API cambia.
- `react-router-dom` 6 → 7 (moderada, 2 avisos): cambio de versión mayor.
- Las 9 restantes del backend (symfony/laravel) se resuelven con el siguiente parche de Laravel.

## Criterios de aceptación 33a
- `infra/audit.sh --strict` del backend sin altas ni críticas.
- Suite completa del backend y del frontend (tsc + vitest) en verde.
