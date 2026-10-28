# Pre-QA SCRUM-1020 — Dashboard de actividad (Auditoría → Actividad), 2026-10-02

Entorno: stack local (nginx :8090, Vite :5173), rama `dev`, commits locales sin push (backend c7283432, frontend 716c9ff). Playwright CLI (specs temporales `atlanticerp-frontend/e2e/_preqa_scrum1020_*.spec.ts`, sin commitear) + API con curl/python. Sin mockup: Visual Review no aplica. Capturas en `capturas/`.
Fixtures sembradas (46 filas de `audit_logs` con `metadata.preqa=1`, 500K filas de carga con `perf=1`, usuario 36 renombrado a un nombre largo) borradas/restauradas al terminar (0 fixtures; `Designer Demo` restaurado).

## Veredicto final
**LIMPIO para pasar a QA, sin hallazgos bloqueantes.** Hay 2 MEDIO no bloqueantes (a decidir por Arquitecto/PM si se corrigen antes de QA) y varios BAJO.

## Resultado por criterio
| # | Criterio | Resultado |
|---|---|---|
| 1 | Acceso | PASA. API: superadmin 200; Gerencia (Gerencia1), Líder de Compras, Técnico, Vendedor 403; sin token 401; token inválido 401. UI: solo superadmin ve el grupo Auditoría con "Consumo IA / Actividad / Logs" (es y en: Activity); Gerencia, Admin&Contab y Técnico no ven el grupo; la ruta directa `/auditoria/actividad` los redirige a su home (capturas `a-*.png`, `a3-*.png`). |
| 2 | Datos (46 filas sembradas, 08-01..08-03, 13 usuarios, filas NULL, los 9 tipos, 9 módulos) | PASA. Tarjetas 41 / 13 / 75.6% / 3; por tipo 19+11+1+2+1+1+2+3+1 = 41; por módulo crm 14, ventas_diseno 8, compras 6, auth 3, security 3, admin_contab 2, biblioteca 2, bodega 2; por día 8/18/15; usuarios top 10 con "Sistema" (9) para NULL; coincide con SQL independiente. `admin_contab`/`biblioteca` se ven legibles ("Admin Contab", "Biblioteca"). |
| 3 | Filtro de fechas | PASA. Por defecto últimos 7 días (hoy en Panamá); presets 7/30/90 correctos; rango manual OK; 91 días, invertido y vacío (inicio, fin o ambos) bloquean con mensaje y NO disparan request; exactamente 90 días funciona (UI y API 200); API 422 para >90, invertido, vacío, formato inválido (`abc`, `2026-8-1`, `2026-02-30`), falta `to`. |
| 4 | Zona horaria Panamá | PASA. 23:30 Panamá del 08-05 cuenta en 08-05; 00:30 del 08-06 en 08-06; también 00:00, 23:59:59 y 00:00 del día siguiente en su día. Dashboard (3 y 2) y serie por día coinciden con SQL. |
| 5 | Coherencia con Logs | PASA. Total del dashboard = `total` de `/admin/audit/logs` para 5 rangos (41, 3, 5, 128, 82). Ojo: el dashboard cachea 60 s, así que con actividad en curso puede ir atrás de Logs hasta 1 min (observación BAJO). |
| 6 | Drill-down | PASA con matices. Usuario ([persona], id 20) -> `/auditoria/logs?from&to&user_id=20`, rango aplicado, 9 filas (= barra). Módulo CRM -> select "CRM", 14 filas. Día 08-02 -> from=to=08-02, 18 filas. "Sistema" no navega. La barra de tipo no navega (el ticket no lo pedía). Matices en hallazgos M1 y B3. |
| 7 | Rendimiento | PASA. Con 150K filas: 90 d en frío 120 ms, 30 d 101 ms, 7 d 57 ms, con caché 24 ms. Con 500K filas (la cifra del ticket): 90 d en frío mediana 254 ms, p95 260 ms, máx 268 ms; 60 d p95 230 ms; 30 d p95 141 ms; 7 d p95 84 ms (objetivo p95 < 500 ms). Memoria con `memory_limit=256M` y 500K filas: pico de proceso 52.5 MB, igual a la línea base del proceso, sin crecimiento medible (< 64 MB). Nota: esa medición incluyó un `Cache::flush()` en el Redis local compartido, sin efecto persistente. |
| 8 | Estados | PASA. Sin actividad: ceros, "Sin actividad en este rango" en las 4 gráficas y serie continua; error 500: alerta visible, sin datos viejos; carga: skeleton `animate-pulse`; carrera (respuesta lenta del rango A llegando después de elegir B): muestra los datos de B (5), no los viejos. |
| 9 | UI | PASA con matices. Responsive 390 / 768 / 1440: 0 px de overflow del documento y 0 elementos fuera de pantalla; modo oscuro real (toggle) correcto; es/en completos, 0 claves sin traducir (módulos no presentes en `logs.json` se humanizan); color único de marca #5BA5A0; sin emojis; Logs sin parámetros en la URL funciona igual (50 filas, filtros vacíos). Matices B1, B2. |

## Hallazgos
**MEDIO M1 — Drill-down de módulos que no están en el selector de Logs deja el filtro invisible.** Clic en la barra `admin_contab` o `biblioteca` abre `/auditoria/logs?...&module=admin_contab` con la tabla filtrada (2 filas), pero el selector de módulo de Logs muestra "Todos los módulos" porque su lista fija (IA, Autenticación, Bodega, Compras, CRM, Seguridad, Servicios, Configuración, Ventas & Diseño) no incluye esos módulos. El usuario ve un filtro "Todos" con resultados filtrados y solo "Limpiar filtros" lo quita. Repro: Actividad, rango 2026-08-01..08-03 (o cualquiera con actividad de Admin&Contab), clic en la barra "Admin Contab". Origen probable: `LogsPage.tsx` (opciones del select de módulo) — agregar las opciones faltantes o derivarlas de `logs:module.*` + los módulos devueltos.

**MEDIO M2 — `GET /api/admin/audit/logs` responde 500 (con el mensaje SQL) ante `from`/`to` inválidos, y ahora es alcanzable por URL.** `AuditLogController::index` (líneas ~97-105) usa `whereDate` sin validar; `LogsPage` ahora siembra `from`/`to` desde la URL, así que `/auditoria/logs?from=garbage` provoca 500 (dos reintentos, tabla vacía sin mensaje claro). Preexistente en el endpoint, pero SCRUM-1020 abre el vector. Fix chico: validar `date_format:Y-m-d` en el controller (422) o ignorar valores inválidos en `LogsPage`. Solo superadmin; los parámetros van ligados (sin inyección).

**BAJO**
- B1. Dashboard, etiquetas de usuario largas se recortan por la izquierda en el gráfico (se ve "upe Fernanda [persona] de la Cruz…"); sin tooltip legible. Prueba con un nombre de ~80 caracteres.
- B2. Modo oscuro: los textos de ejes y etiquetas de los gráficos (canvas) quedan en gris tenue sobre fondo azul oscuro (contraste aproximado 3:1, por debajo de AA). Legible pero débil.
- B3. Un módulo cuyo `action` no lleva punto (p. ej. `weird_action`) aparece en el dashboard pero el drill-down a Logs devuelve 0 filas (Logs filtra por `modulo.%`). Teórico: las acciones reales llevan punto.
- B4. El filtro `module` de Logs usa `LIKE 'modulo.%'` sin escapar `_` (`ventas_diseno`): `_` es comodín. Preexistente.
- B5. Caché de 60 s sin botón de refrescar: un evento nuevo tarda hasta 1 min en verse y el total puede ir hasta 1 min por detrás de Logs.
- B6. El mensaje de error del servidor se muestra tal cual en inglés ("Server Error") en una app en español.
- B7. Etiquetas de la serie por día sin año ("08-01"): ambiguo si un rango de 90 días cruza de año.
- B8. Los tipos en la respuesta son 9 (created, updated, deleted, exported, downloaded, login, login_failed, access_denied, other) y el ticket dice "8" (agrupa login fallido aparte); la UI los muestra bien.

## Lo que sí funciona
Gate superadmin en API y UI (401/403 correctos); agregados exactos contra SQL independiente; zona horaria Panamá en bordes de día; rango máximo 90 días exacto en UI y API; sin requests con rangos inválidos; estados de carga/error/vacío/carrera; coherencia de totales con Logs; rendimiento muy por debajo de 500 ms y de 64 MB con 500K filas; responsive, oscuro, es/en, sin emojis; Logs sin parámetros sin regresión.
