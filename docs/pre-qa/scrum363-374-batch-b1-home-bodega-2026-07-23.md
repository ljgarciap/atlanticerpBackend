# Pre-QA — Batch B1, "Home de Bodega" (SCRUM-363→374)

**Fecha:** 2026-07-23
**Alcance:** `atlanticerp-backend` hasta `25df57f` (dev, sin push) + `atlanticerp-frontend` hasta `0befa7b`
(dev, sin push). Senior Review de ambos lados: 🟢 Aprobado, sin blockers
(`docs/reviews/batch-b1-home-bodega-{backend,frontend}-2026-07-23.md`).
Verificación corrida contra el stack Docker local (`infra-laravel-1`, `infra-postgres-1`,
`infra-nginx-1`, puerto `8090`), usuarios reales de `BusinessRoleUserSeeder` (Lider Bodega Test
`almacen@atlantic.com.pa`/lider_bodega, Asistentebodega [persona] `logistica@atlantic.com.pa`/
asistente_bodega, Asistentebodega2 [persona] `almacen2@atlantic.com.pa`/asistente_bodega, Auxiliarbodega
[persona] `auxiliarbodega@test.com`/ayudante_general_bodega), login real vía
`POST /api/auth/login` (password = email), llamadas `curl` directas a la API — no solo lectura de
código.

## Veredicto: 🔴 BLOQUEADO — no pasa a QA

1 hallazgo CRÍTICO (viola un criterio de aceptación explícito y negativo de SCRUM-365/366,
confirmado en vivo con requests reales) + 2 hallazgos MEDIO + 1 punto de negocio pendiente de
confirmación de Luis/Analista (ya señalizado por el propio batch). El resto de los 12 tickets pasó
limpio. Ningún marcador de gate creado, ninguno de los 12 tickets transicionado a `QA` — el batch
completo queda retenido hasta que se resuelva el hallazgo CRÍTICO (afecta la premisa central de
SCRUM-365/366, que 5 de los otros 10 paneles heredan vía el mismo alcance Mío/Equipo).

---

## 🔴 CRÍTICO — Asistente de Bodega puede usar el alcance "Equipo" completo, contradice Escenario 1 de SCRUM-365

**Tickets afectados: SCRUM-365, SCRUM-366** (y transitivamente cualquier panel que dependa del
alcance Mío/Equipo: SCRUM-367, 369, 370, 372).

**Criterio de aceptación violado** (texto literal de SCRUM-365, Escenario 1 y sección PERMISOS):
> Dado que un Asistente de Bodega (no Jefe de Bodega) ve el botón "Equipo", cuando lo presiona,
> entonces el sistema debería impedir el acceso o mostrar una advertencia de permiso, sin
> permitirle ver la carga de todo el equipo. [...] PERMISOS: Alcance "Equipo" exclusivo del perfil
> Jefe de Bodega. Asistentes/Operativos solo ven "Inicio" (su propia carga).

**Reproducido en vivo, no en teoría — API directa, saltando cualquier gate de UI:**

```
$ curl -s http://localhost:8090/api/bodega/home/team \
    -H "Authorization: Bearer <token de Asistentebodega [persona], asistente_bodega>"
{"data":[
  {"id":15,"name":"Auxiliarbodega [persona]"},
  {"id":12,"name":"Lider Bodega Test"},
  {"id":16,"name":"[persona] [persona]"},
  {"id":14,"name":"Asistentebodega2 [persona]"},
  {"id":13,"name":"Asistentebodega [persona]"}
]}

$ curl -s "http://localhost:8090/api/bodega/home/summary?scope=team&owner_id=14" \
    -H "Authorization: Bearer <token de Asistentebodega [persona]>"
{"saludo":{"nombre":"Asistentebodega [persona]", ..., "resumen":"El equipo tiene 0 pedidos asignados hoy..."}, ...}
```

Asistentebodega (asistente_bodega) obtiene 200 en ambos endpoints y puede filtrar por Asistentebodega2
(`owner_id=14`, otro Asistente) exactamente igual que si fuera el Jefe — sin ningún 403 ni
advertencia. No es un caso de "el botón sigue visible en el front pero el backend bloquea" (el tipo
de hallazgo que Batch A4/ownership ya dejó como lección) — acá el propio backend permite el acceso
completo.

**Causa raíz — conflicto de specs entre dos tickets, no un bug de código nuevo:**
`BodegaHomeService`/`BodegaHomeController` usan `User::canViewTeamV2(ModuleCatalog::BODEGA)`, el
mismo mecanismo genérico ya aprobado y en uso por Ventas & Diseño Home y Compras Home (reutilización
correcta del patrón arquitectónico, no una implementación paralela). Ese mecanismo lee
`role_module_visibility.can_view_team` (schema `atlantic_auth`). Confirmado en la base real:

```
role_id=6 (lider_bodega)      can_view_team=true
role_id=7 (asistente_bodega)  can_view_team=true   ← el problema
role_id=8 (ayudante_gral)     can_view_team=false
```

La migración `2026_07_11_100001_create_auth_role_module_visibility_table.php` (Fase A, ADR-006)
siembra `asistente_bodega`/`bodega` con `view_team: true` citando explícitamente **SCRUM-144**
como la fuente que "lo confirma así", y deja anotado en el propio comentario que SCRUM-145 decía
"Ver: a confirmar" para esa fila pero se priorizó SCRUM-144. SCRUM-365/366 (2026-07-20, posteriores
y mucho más específicos) dicen exactamente lo contrario para la pantalla Home. `canViewTeamV2
(BODEGA)` no tiene ningún otro consumidor en el código hoy (confirmado con grep) — Backend Dev no
rompió nada existente, pero heredó una configuración de datos que no es compatible con el
criterio de aceptación de este batch.

**Por qué no lo resuelvo yo mismo (no es un fix chico):**
- Cambiar la fila de `role_module_visibility` revertiría una decisión ya registrada como
  proveniente de otro ticket aprobado (SCRUM-144) — es una decisión de negocio/producto, no una
  decisión técnica dentro del diseño aprobado.
- La alternativa (ignorar el flag genérico y hardcodear "solo `lider_bodega`" dentro de
  `BodegaHomeService`) es un cambio de diseño real: dejaría de reusar el mecanismo compartido
  `canViewTeamV2()` que Ventas & Diseño y Compras también usan, y el propio Arquitecto tendría que
  aprobar esa divergencia.
- Ambas opciones tocan "esquema de módulos/modelo de datos" y "decisiones de negocio" — por regla
  del proyecto, escalan a Arquitecto/Luis, no las resuelve un agente en el momento.

**Recomendación para PM/Arquitecto/Luis:** decidir explícitamente entre (a) confirmar que
SCRUM-144 queda revertido para `asistente_bodega`/módulo `bodega` — hoy sin otro consumidor real,
así que es seguro a nivel de código — y ajustar el dato, o (b) que Home implemente una regla propia
más estricta ("Equipo" exclusivo de `role_key = lider_bodega`, no de `can_view_team` genérico) sin
tocar la tabla compartida. Cualquiera de las 2 vuelve a Backend Dev antes de una nueva pasada de
Pre-QA.

---

## 🟡 MEDIO — El propio Jefe de Bodega aparece en su lista de personas filtrables

**Ticket: SCRUM-366.**

REQ-296 dice explícitamente: "una [opción] por cada Asistente/Operativo de Bodega" y su RN3: "la
lista de personas debe generarse dinámicamente según los Asistentes/Operativos activos de Bodega".
En ningún lado se menciona al Jefe como una opción del selector — tiene sentido, un Jefe filtrando
"por sí mismo" dentro de "Equipo" no aporta nada que "Inicio" no muestre ya.

Confirmado en vivo: `GET /bodega/home/team` logueado como Liderbodega (el propio Jefe) devuelve 5
personas, incluido él mismo ("Lider Bodega Test"). Causa: `BodegaHomeService::team()` usa
`BodegaRoles::ALL`, que incluye `LIDER_BODEGA`.

No lo corrijo en el momento porque toca el mismo área de código que el hallazgo CRÍTICO de arriba
(`bodegaRoleIds()` es compartida por `team()`, `isBodegaTeamMember()` y `teamEmails()`) — mejor que
Backend Dev lo resuelva en la misma pasada, con un constante nueva tipo
`BodegaRoles::TEAM_SELECTABLE` (sin `LIDER_BODEGA`) usada solo en `team()`, sin tocar el guard de
`owner_id` ni la agregación de calendario, cuyo alcance correcto respecto al Jefe no está
confirmado explícitamente en ningún ticket.

---

## 🟡 MEDIO — El texto de "Saludo" no distingue Equipo completo / persona filtrada / fallback

**Ticket: SCRUM-364 (consume el flag de SCRUM-365/366).**

`BodegaHomeService::saludo()` arma el `resumen` solo a partir de un booleano
(`scope==='team' && canViewTeamV2()`), sin considerar si en realidad se está mostrando:
- el consolidado de todo el equipo,
- los datos de una persona específica filtrada (`owner_id` válido), o
- el propio alcance del actor por fallback de un `owner_id` inválido (guard anti-CRÍTICO ya
  probado y correcto en sí mismo).

Confirmado en vivo — los 3 casos devuelven el mismo texto pese a mostrar datos distintos:

```
scope=team (sin owner_id, Liderbodega)         → "El equipo tiene 0 pedidos..."   (correcto: es el equipo)
scope=team&owner_id=13 (Asistentebodega, Liderbodega)  → "El equipo tiene 0 pedidos..."   (dato real: solo Asistentebodega)
scope=team&owner_id=34 (inválido, Liderbodega) → "El equipo tiene 0 pedidos..."   (dato real: solo Liderbodega, por fallback)
```

No es una fuga de datos (los paneles numéricos sí están bien filtrados), pero el copy puede hacer
creer al Jefe que un número es del equipo completo cuando es de una sola persona. Fix acotado
(pasar el modo real a `saludo()`), recomendado para la misma pasada de Backend Dev que resuelva el
CRÍTICO.

---

## Punto de negocio pendiente de confirmación (no bloqueante, ya señalizado por el propio batch)

**SCRUM-369** — "descuadre de inventario" se interpretó como `AdjustmentRequestLine` en
`ESTADO_PENDIENTE`. Verificado que funciona en vivo (fabricé una línea pendiente vía tinker antes
de este batch y ya estaba cubierta por test automatizado
`test_pendientes_incluye_solicitud_de_ajuste_pendiente_del_propio_solicitante`). Interpretación
razonable — es el único concepto real del modelo de datos equivalente a "Bodega reportó una
discrepancia y espera resolución" — pero sigue siendo una interpretación del batch, no una
confirmación literal de ningún ticket. Recomiendo que Luis/Analista lo confirme explícitamente
antes de dar el criterio por cerrado, mismo pedido que ya dejó anotado el propio Backend Dev en el
docblock del método.

---

## Resto del batch — pasada limpia, verificado en vivo

- **SCRUM-363** — las 4 rutas placeholder del dropdown "Inventario" (`/bodega/inventario`,
  `/bodega/ordenes-zona-libre`, `/bodega/inventario-general`, `/bodega/devoluciones`) están
  montadas en `App.tsx` con su propio `<Route>` — no hay riesgo de 404 al hacer clic. Confirmado
  además que las 4 usan `ComingSoonBodegaPage` con ícono real (`IcoBox`), sin emoji.
- **SCRUM-364** — el saludo muestra el nombre real del usuario autenticado en cada caso (Liderbodega ve
  "Lider Bodega Test", Asistentebodega ve "Asistentebodega [persona]") — no hay fuga de nombre entre usuarios.
- **SCRUM-367** — con 0 pedidos asignados hoy (caso real de Liderbodega en el dataset actual de dev),
  `percent_despachado_hoy`/`percent_a_tiempo` devuelven `null` — no hay división por cero ni error
  5xx.
- **SCRUM-368** — credenciales Azure reales configuradas en el entorno local; confirmé en
  `storage/logs/laravel.log` que las 4 llamadas reales a Graph para los usuarios de Bodega no
  generaron ningún `Log::warning`/error — el panel no rompe con calendario vacío. **Limitación
  honesta:** no verifiqué el camino "SÍ hay eventos" con un evento real (ninguno de los 4 usuarios
  tenía algo agendado hoy al momento de la prueba) — solo el camino vacío quedó confirmado contra
  Graph real.
- **SCRUM-370** — fabriqué en vivo (tinker) un pedido con `committed_delivery_date` a 5 días;
  confirmé que **no aparece** en `despachos_urgentes` (la ventana configurada es 1 día — hoy o
  mañana). Dato de prueba limpiado después.
- **SCRUM-371** — fabriqué una orden de compra en tránsito (tinker) y confirmé en vivo que Liderbodega
  y Asistentebodega (ninguno tiene `compras.read`) obtienen `200` en `GET /api/compras/orders/{id}` gracias
  al fix de permiso (`bodega.read` ahora también habilita esa ruta) — antes del fix habría sido
  `403`. Dato de prueba limpiado después.
- **SCRUM-372** — `estado_pedidos.stages` devuelve exactamente 7 etapas
  (`asignado/picking_pendiente/en_picking/packing/por_despachar/despachado/entregado`) — **sin**
  ningún chip `por_recibir`, confirmado en vivo.
- **SCRUM-373/SCRUM-374** — el link "Ver los N artículos" de ambos paneles apunta a
  `/bodega/inventario?filter=...`; confirmado que esa ruta ya está montada (mismo placeholder de
  SCRUM-363) y no rompe con el query string.
- **Regla SCRUM-56 (sin emoji)** — grep independiente (rangos Unicode de emoji) sobre los archivos
  del batch: cero matches en JSX/i18n, solo 2 en comentarios de código (ya señalados por Senior
  Review), mismo resultado.
- **Concurrencia/doble clic** — disparé `scope=own` y `scope=team` casi simultáneos contra el mismo
  usuario: ambas resoluciones devuelven `200` sin error ni condición de carrera visible.
- **Recarga a mitad de sesión con "Equipo" activo** — el estado del toggle es `useState` local en
  `BodegaHomePage.tsx`, no persistido en la URL. Al recargar vuelve a "Inicio", que es exactamente
  RN1 de SCRUM-365 ("por defecto, al entrar a Home, el alcance activo es Inicio") — no es un bug,
  es el comportamiento esperado.
- **Endpoint sin ningún query param** — confirmado que asume `scope=own` correctamente (el saludo
  dice "Tienes..." no "El equipo tiene...").

## Siguiente paso

1. PM escala el hallazgo CRÍTICO a Arquitecto + Luis (conflicto SCRUM-144 vs SCRUM-365/366).
2. Con la decisión tomada, Backend Dev implementa el fix (más el MEDIO del selector de personas y
   el MEDIO del texto de saludo, mismo área de código) → nueva pasada de Pre-QA, checklist
   COMPLETO, no solo el hallazgo puntual.
3. Recién con pasada limpia se crean los 12 marcadores de gate y se transicionan los 12 tickets a
   `QA`.
