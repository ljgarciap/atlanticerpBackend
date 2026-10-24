# SCRUM-984 — Manuales de Usuario + biblioteca PDF/video: desglose del PM

Insumos aprobados por Luis: diseño AWS (`biblioteca-manuales-s3-diseno.md`), diseño técnico del Arquitecto
(módulo `App\Modules\Biblioteca`, catálogo en S3 sin tabla), spec visual (`docs/specs/manuales-usuario-ux.md`).

Decisiones de negocio: video hasta 500 MB, descarga permitida, PDF 50 MB, mp4/webm, carga solo
superadmin + Daniela (`manuales.manage`). Topes, formatos y TTL en `auth.system_settings`, nunca constantes.

## Contrato API (fijado por el PM, lo confirma el Arquitecto)
- `GET /api/biblioteca?modulo=&tipo=` -> `[{id, name, module, type, size_bytes, created_at}]`, cualquier autenticado.
- `GET /api/biblioteca/{id}/url?accion=ver|descargar` -> `{url, expires_at}`.
- `GET /api/biblioteca/config` -> `{max_pdf_bytes, max_video_bytes, allowed_extensions, modules}`, requiere `manuales.manage`.
- `POST /api/biblioteca/uploads` -> `{upload_id, url, headers}`; `POST /api/biblioteca/uploads/{id}/finalize`; `DELETE /api/biblioteca/{id}` (los tres con `manuales.manage`).
- `module` es uno de: crm, ventas_diseno, compras, bodega, servicios, admin_contabilidad, gerencia.

## Tareas
| ID | Rol | Tarea | Depende de |
|---|---|---|---|
| D1 | DevOps / AWS | Crear bucket `atlanticerp-library`, 3 usuarios IAM, policy, CORS, lifecycle (ejecuta Luis o con aprobación nativa por comando; nunca en modo autónomo) | aprobación de Luis |
| D2 | DevOps | Variables `AWS_LIBRARY_*` en .env de dev, luego test y prod (archivo sensible, prompt nativo) | D1 |
| D3 | DevOps | CloudTrail data events del bucket | D1 |
| B1 | Backend | Permiso `manuales.manage` en catálogo + grant a Daniela en `SpecialPermissionSeeder` + claves en `system_settings` (seeder) | — |
| B2 | Backend | Disco `s3-library`, `LibraryService` (listar por prefijo, sidecar JSON, caché Redis 60 s) | B1 |
| B3 | Backend | Endpoints de lectura: listado, URL prefirmada, config | B2 |
| B4 | Backend | Uploads + finalize (HeadObject, magic bytes, `S3Client::copyObject`) + delete lógico + `AuditService` + rate limit | B2 |
| B5 | Backend | Comando Artisan que sube los 7 PDFs del ticket, una sola vez | B4, D1 |
| B6 | Backend | PHPUnit (permisos, validación, finalize con S3 simulado), PHPStan nivel 8 | B3, B4 |
| F1 | Frontend | Iconos nuevos, i18n `manuales` + `nav.manuales`, entrada en Sidebar tras Soporte, ruta `/manuales` | — |
| F2 | Frontend | ManualesPage: filtros, tarjetas, estados, responsive | F1 (mock del contrato) |
| F3 | Frontend | Modal de video con renovación de URL | F2 |
| F4 | Frontend | Panel Administrar (subida XHR, progreso, cancelar, reintentar, borrar) | F2, B4 |
| F5 | Frontend | Vitest + spec Playwright permanente en `e2e/` | F2-F4 |
| T1 | Tech Writer | ADR, manual de usuario por rol, línea en `CLAUDE.md` del proyecto | en paralelo |

## Orden y gates
1. Arrancan en paralelo: B1, F1 y la preparación de D1.
2. Backend y frontend como fork del mismo diseño, con el contrato de arriba. F2-F3 con datos simulados hasta que B3 esté.
3. Integración real contra S3 solo cuando D1 y D2 estén listos en dev.
4. Senior Reviewer + Cybersecurity en paralelo. Visual Reviewer + Pre-QA fusionados, con la spec visual como referencia por no haber mockup. Luego QA.
5. Gate real antes de cualquier push: `infra/test.sh` completo + `npx vitest run` + spec Playwright del módulo. Nunca `npm run test`.
6. Promoción `dev -> test` con push completo; ningún push sin aprobación.

## Riesgos
- Checkout compartido con otro equipo: el frontend tiene archivos `_preqa_*` sin commitear ajenos a este ticket; no entran en los commits del 984.
- D1 bloquea la integración real, no el desarrollo.
- No verificado aún: nombre de bucket libre, SCP de cuenta, instance profiles de las EC2.
