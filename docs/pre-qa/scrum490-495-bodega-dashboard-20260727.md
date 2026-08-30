# Pre-QA — Reportes de Bodega (SCRUM-490→495, REQ-420→425)

Fecha: 2026-07-27
Corre después de: Senior Review + Visual Review (ambos con hallazgos ya corregidos — i18n de
pluralización en 491/494, indicador de período faltante en 490).
Entorno probado: `dev.atlanticerp.ai` (backend + frontend ya desplegados), más `infra/test.sh` local
para PHPUnit y `npx vitest` local para el frontend.
Fuente de verdad: Jira (SCRUM-490→495) + `3__Requerimientos_Bodega.xlsx` (hoja "Historias de
Usuario", filas REQ-420→425) + mockup `3G__Bodega_Reportes.html`. Excel y Jira coinciden
palabra por palabra en este batch — sin conflicto que reconciliar.

## Paso 0 — permisos angostos / valores paramétricos / dependencias UI

- Umbral de concentración (REQ-423 RN1, "75% o más"): vive en
  `bodega_settings.reports_capacidad_concentracion_pct` vía `BodegaSettingsService`, CRUD real en
  `BodegaSettingsPage.tsx` (`PUT /api/bodega/settings`, permiso `bodega.settings.configure`). No
  hardcodeado — confirmado leyendo el código y con `test_capacidad_por_bodega_umbral_configurable`
  (cambia a 50%, confirma que el reporte reacciona). No se encontró ningún otro umbral/margen/
  porcentaje sin declarar en este batch (revisión de literales numéricos en
  `BodegaReportsService`/`BodegaReportsController`/`ReportPeriod` — los únicos números son
  matemática de porcentaje, `100`/`1` de redondeo, no reglas de negocio).
- No hay ningún criterio "solo X persona puede..." en este batch (los 6 tickets dicen "Visible
  para todos los perfiles de Bodega") — Paso 0 de permisos angostos no aplica acá.
- Dependencia de UI de otro ticket (SCRUM-495 navega a Pedidos/Solicitud de ajuste/Bodegas/Ver
  inventario): las 4 pantallas destino ya están desplegadas y funcionales en `dev.atlanticerp.ai`
  (verificado navegando a las 4 en vivo, ninguna en estado "Próximamente").
- Dato sembrado: no hay un seeder dedicado a este dashboard, pero el entorno tiene datos orgánicos
  reales (pedidos, solicitudes de ajuste, stock) acumulados de sesiones previas — suficiente para
  el camino feliz. Los escenarios "vacíos" (Escenario 2 de 491/494, "0 solicitudes" de 492) no se
  pueden forzar en `dev.atlanticerp.ai` sin borrar datos reales (el selector de período no admite un
  rango arbitrario, solo mes/trimestre/año desde `now()`) — se verificaron en cambio con
  `infra/test.sh` (PHPUnit, DB fresca por test) + `vitest` (mocks), ver detalle abajo.

## Paso 2-3 — Intentos de ruptura por ticket

### SCRUM-490 (REQ-420) — selector de período
- **Cambios de período en ráfaga** (Trimestre→Año→Mes→Trimestre→Mes sin esperar entre clics):
  las 4 tarjetas resuelven a contenido real, sin loading colgado. TanStack Query cachea por
  `[endpoint, period]`, cada período tiene su propia entrada — no hay estado compartido mutable
  que se pise entre sí.
- **Label de período vs datos — posible race condition**: el label (`formatReportPeriod`) se
  calcula 100% síncrono en el cliente a partir de `period` (no depende de la respuesta del
  backend), así que nunca puede mostrar un período distinto al botón activo — descartado
  estructuralmente, no solo probado.
- **Recargar a mitad de un cambio de período**: se cambia a "Año", se recarga la página — vuelve
  al default ("Mes"), sin crash ni estado roto (no hay persistencia de período entre navegaciones,
  comportamiento razonable, no viola ningún criterio).
- Verificado con Playwright real contra `dev.atlanticerp.ai` (3 tests, ver
  `atlanticerp-frontend/e2e/preqa-scrum490-495-reportes-bodega.spec.ts`).

### SCRUM-491 (REQ-421) — ranking de productividad
- **Escenario 2 (nadie gestionó pedidos)**: cubierto con `infra/test.sh`
  (`test_productividad_sin_pedidos_en_el_periodo_indica_sin_datos`, DB fresca) — `has_data: false`
  + mensaje explicativo, no un ranking vacío. Reproducido también a nivel de componente
  (`BodegaReportesPage.test.tsx`, mock `has_data:false`).
- **Asistente desactivado a mitad del período**: `test_productividad_excluye_asistente_inactivo`
  confirma que un asistente inactivo NO aparece en el ranking — correcto por RN1 ("todos los
  Asistentes de Bodega **activos**"), no es un bug.
- **Exactamente 1 asistente activo con pedidos**: no reproducible en vivo (siempre hay ≥2
  asistentes activos reales en el roster), pero la fórmula `percent_of_top = count/max*100` es
  determinística — con un solo elemento, `max == count`, da 100% por construcción matemática, sin
  rama de código especial que pueda romperse.

### SCRUM-492 (REQ-422) — precisión de inventario
- **Los 3 KPIs suman el total exacto**: `test_precision_inventario_los_3_kpis_suman_el_total`
  (14 = 9+3+2 con datos mixtos reales, más un registro fuera de período que NO debe contar).
  Verificado también en vivo contra `dev.atlanticerp.ai` con datos orgánicos.
- **0 solicitudes en el período**: no hay un Escenario 2 explícito en Jira/Excel para este ticket
  (a diferencia de 491/494) — el frontend muestra el mensaje "No hay solicitudes de ajuste para
  ese período" en vez de 3 KPIs en 0 (`total === 0` → mensaje, no grid). No contradice ningún
  criterio escrito; comportamiento razonable, sin error ni pantalla rota.
- **Motivo con texto libre inusual**: `test_precision_inventario_agrupa_motivos_reales` usa
  motivos reales tal cual los capturó el usuario ("Producto dañado / roto", "Conteo físico no
  coincide") — RN2 confirmado (no es una lista fija).

### SCRUM-493 (REQ-423) — capacidad + aviso 75%
- **Boundary exacto (75.0% dispara, 74.9% no)**: verificado con una prueba PHPUnit temporal
  (`test_preqa_temp_boundary_exacto_75_dispara_aviso` / `..._749_no_dispara_aviso`, revertida tras
  confirmar — no se commiteó). Confirmado: `percent_of_total >= threshold_pct` dispara el aviso
  (comparación real en código: `< $thresholdPct` → null, así que `== $thresholdPct` SÍ dispara).
- **Umbral realmente configurable**: `test_capacidad_por_bodega_umbral_configurable` cambia el
  umbral a 50% vía `PUT /api/bodega/settings` y confirma que el reporte usa el nuevo valor sin
  deploy — mismo patrón que pide el ticket.

### SCRUM-494 (REQ-424) — resumen de inventario
- **Catálogo sin productos en atención**: `test_resumen_inventario_sin_productos_en_atencion_da_cero`
  confirma `in_attention: 0` explícito (no espacio en blanco, no error). Reproducido también a
  nivel de componente (`BodegaReportesPage.test.tsx`, mock `in_attention: 0` → el KPI muestra "0").
- **Coincide con Ver Inventario en el mismo momento**: `test_resumen_inventario_coincide_con_ver_inventario`
  compara contra `BodegaInventoryKpiService::kpis()` (el mismo servicio real que usa "Ver
  Inventario", no un cálculo paralelo) — coincidencia exacta garantizada por diseño, no solo por
  el test.

### SCRUM-495 (REQ-425) — navegación de las 4 tarjetas
- **Click en cualquier punto de la superficie** (no solo el ícono): probado clickeando el
  subtítulo de "Capacidad por bodega" — navega igual que clickear el título o la flecha
  (`role="button"` está en el `<div>` contenedor completo, el `className="p-4..."` con el padding
  vive en ese mismo div).
- **Doble clic**: no dispara doble navegación ni deja el historial con 2 entradas duplicadas — un
  solo "atrás" vuelve a Reportes.
- **Teclado (Enter/Space) sobre la tarjeta enfocada**: ambas teclas navegan correctamente
  (Inventario→`/bodega/inventario` con Enter, Productividad→`/bodega/pedidos` con Space).
- Las 4 verificadas en vivo contra `dev.atlanticerp.ai`.

### Genérico (Paso 3)
- **Rol sin `bodega.read` por URL directa**: `designer@atlantic.test` (sin `bodega.read`)
  navegando a `/bodega/reportes` es redirigido a `/dashboard` — bloquea de verdad (`RequirePermission`
  en `App.tsx`), no solo esconde el link del sidebar. Mismo gate confirmado a nivel API con
  `test_reportes_requieren_bodega_read` (403 en los 4 endpoints).

## Paso 4 — cómo se corrió

- Backend: `infra/test.sh --filter=BodegaReportsControllerTest` — 12 tests originales + 2 probes
  temporales de boundary (revertidas, no commiteadas) → 14/14 verdes.
- Frontend: `npx vitest run src/pages/BodegaReportesPage.test.tsx` → 8/8 verdes.
- E2E real: `npx playwright test e2e/preqa-scrum490-495-reportes-bodega.spec.ts` contra
  `https://dev.atlanticerp.ai` → 8/8 verdes. Test permanente (promovido a `e2e/`, no desechable) — cubre
  2 gates que vale la pena no perder: navegación de tarjeta completa (SCRUM-495) y el gate de
  permiso `bodega.read` a nivel de ruta.

## Resultado

CRÍTICO — ninguno.
MEDIO — ninguno.

Lo que sí funciona (verificado activamente, no solo leído):
- Selector de período recalcula los 4 reportes a la vez, sin loading colgado ante clics en ráfaga.
- Label de período nunca se desincroniza de los datos (diseño síncrono, no solo "no se rompió en
  la prueba").
- Ranking de productividad excluye correctamente asistentes inactivos y maneja el caso "nadie
  gestionó pedidos" con mensaje explicativo.
- Los 3 KPIs de precisión de inventario suman exacto el total del período, con datos mixtos reales.
- Tabla de motivos refleja texto libre real, no una lista fija.
- Aviso de concentración dispara exactamente en el umbral configurado (75.0% sí, 74.9% no) y
  reacciona a un cambio de umbral sin deploy.
- Resumen de inventario coincide exacto con Ver Inventario (mismo servicio, no cálculo paralelo) y
  muestra 0 explícito cuando corresponde.
- Las 4 tarjetas navegan a su pantalla específica, con toda la superficie clickeable, sin doble
  navegación en doble clic, y accesibles por teclado.
- Gate de permiso `bodega.read` bloquea de verdad el acceso directo por URL, no solo el link del
  sidebar.

**Pasada limpia — los 6 tickets pasan a QA.**
