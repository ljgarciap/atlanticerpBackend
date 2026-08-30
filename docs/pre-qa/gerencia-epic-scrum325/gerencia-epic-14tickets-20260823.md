# Pre-QA adversarial — Epic Gerencia (SCRUM-325), 14 tickets en QA

**Fecha:** 2026-08-23
**Ambiente:** `dev.atlanticerp.ai` (deploy real verificado — commit `9b14ad8`, dashboard ejecutivo
mergeado en `57d22eb`)
**Cuentas usadas:** `whil@illuminations.com.pa` (Gerencia Restringida, Nivel 8 — persona real
nombrada en los tickets: "Como Mark, David o Whil"), `andres.loi@illuminations.com.pa` /
`daniela@illuminations.com.pa` (spec previo de SCRUM-163/164), `neil.quiel@illuminations.com.pa`
(Vendedor/Diseñador, para el negativo de permisos).

**Nota de alcance:** el epic Gerencia (SCRUM-325) es de otro equipo — esta pasada es el pedido
puntual de Luis del 2026-08-23. No se transicionó ningún ticket de Jira ni se tocó código; esto es
reporte puro. `mbekhar@illuminations.com.pa` (Mark) y `andres.loi`/`marly.rangel` con password =
email por defecto dieron "Credenciales inválidas" — ya deben tener password propio, no es hallazgo
de Pre-QA.

---

## Resumen ejecutivo

De los 14 tickets: **2 limpios** (163, 164 — ya tenían spec de Pre-QA del 2026-08-22, re-verificado
hoy contra dev real), **3 bloqueados de origen** (167, 169, 170 — la propia ficha de Jira dice
"CRITERIOS DE ACEPTACION: PENDIENTE DE DEFINIR", nunca se pudo haber verificado nada), y **9 con
hallazgos reales**, de los cuales **6 son CRÍTICOS**.

---

## CRÍTICO

1. **SCRUM-159 (Panel Facturación) — faltan los filtros Vendedor y Cliente que pide RN1.**
   RN1: "Filtros Desde/Hasta/Vendedor/Cliente combinables." En la pantalla real solo existen 2
   `<input type="date">` (Desde/Hasta) — cero `<select>`, cero combobox de ningún tipo en toda la
   página. Verificado por inspección de DOM (`page.locator('select')` → `[]`), no es un problema de
   selector.

2. **SCRUM-159 / SCRUM-156 / SCRUM-160 — los indicadores/tarjetas no navegan a ningún lado.**
   RN3 de Facturación es explícito: "Todo elemento (4 indicadores, 6 barras, link final) es
   clickeable y navega a su detalle filtrado." Click en "Facturado total" (indicador) → permanece
   en `/gerencia`, sin navegar. Mismo resultado en "Total cotizado" y "Monto aprobado" del panel
   Proyectos (SCRUM-160, que dice explícitamente tener "la misma estructura que Facturación"). Solo
   el link "Ver detalle completo por vendedor y cliente" navega de verdad (a
   `/admin-contab/facturacion`). SCRUM-156 (Escenario 2 de su propio Gherkin: "clic en la tarjeta →
   navego al detalle de Facturación/Proyectos") también se queda en `/gerencia` sin navegar — falla
   el criterio de aceptación literal del ticket, no una regla derivada.

3. **SCRUM-160 (Panel Proyectos) — el set de indicadores no es el que pide el ticket.**
   Escenario 1 de Jira: "veo Proyectos cerrados este año, este mes, y ambos márgenes cotizados, en
   ese orden" (4 indicadores específicos). Lo que se ve en pantalla: **Total cotizado / Propuestas
   activas / Tasa de aprobación / Monto aprobado** — ningún indicador dice "Proyectos cerrados", no
   hay separación año/mes, y no hay ningún valor etiquetado como margen (%). No es una cuestión de
   copy — es un set de métricas distinto al que el ticket define.

4. **SCRUM-166 (Salud — Ventas & Diseño) — el click no filtra, navega siempre al mismo lugar.**
   RN1-RN5 exigen que cada uno de los 5 indicadores navegue a un destino **distinto y filtrado**
   (Pipeline por etapa, Pipeline por estancados, Cotizaciones del mes, Pedidos del mes, Pipeline
   sin filtro). En la práctica, clic en "Estancados" y clic en "Aprobados este mes" navegan **al
   mismo lugar**: `/ventas-diseno/home` sin ningún filtro aplicado. Además, el indicador "En Final
   Stage" que pide RN1 no existe como tal en la tarjeta (se ven "Activos/Estancados/Aprobados este
   mes/Perdidos este mes", no el vocabulario del ticket).

5. **SCRUM-168 (Salud — Compras) — mismo patrón: 3 clics, mismo destino sin filtrar, y falta el
   indicador que pide el propio Gherkin.** "Por aprobar", "En tránsito" y "Recibidos este mes"
   navegan los 3 a `/compras/inicio` sin filtro. El Escenario 1 de Jira (el más concreto de todo el
   ticket) depende del indicador **"Bajo stock sin ordenar"** — ese indicador no existe en la
   tarjeta, por lo que ese escenario no se puede ni ejecutar. Tampoco existen "Órdenes críticas" ni
   "Cantidad de pedidos" (RN1/RN4) como tales.

6. **SCRUM-171 (Agenda de Bodega) — "Ver detalle" navega al módulo equivocado.**
   RN2: "Botón 'Ver detalle' navega a Bodega → Rutas de entrega del día completo." En la práctica
   navega a `/ventas-diseno/home` — un módulo sin ninguna relación con Bodega. Confirmado con
   locator acotado exclusivamente al botón de esa tarjeta (usando el matcher amplio por texto
   `'Ver detalle'` sin `exact:true` se pisa con los botones "Ver detalle completo por vendedor y
   cliente" de Facturación/Proyectos — ya descartado ese falso positivo antes de reportar esto).

## MEDIO

7. **SCRUM-157/158 — clic en la tarjeta no hace nada, sin feedback.** Ambos tickets marcan su
   destino de clic como "pendiente de definir", así que no es un bug de ruteo — pero tampoco hay
   ningún feedback (toast "Próximamente", cursor deshabilitado, nada): el clic simplemente no
   produce ningún efecto visible. Vale la pena que quien decida el destino real lo defina antes de
   que esto salga de `QA`, porque hoy el comportamiento es indistinguible de "esto está roto".

8. **SCRUM-157 (CxC al día) — el subtítulo en $0 no sigue el formato que pide RN2.** RN2: "el
   subtítulo muestra el % y el monto total de CxC entre paréntesis, como referencia" — siempre,
   sin excepción documentada. En el estado real (CxC = $0) el subtítulo dice "Sin cuentas por
   cobrar registradas" en vez de algo como "0% ($0 de $0 total)". Puede ser una decisión de UX
   válida para el caso vacío, pero no es lo que el criterio literal pide — que alguien lo confirme
   como variante aceptada antes de dar el ticket por cerrado.

9. **Las 14 fichas (permiso transversal) — `/gerencia` no bloquea la ruta a un rol sin acceso, se
   apoya solo en que el backend devuelve 403.** Logueado como Vendedor/Diseñador
   (`neil.quiel@illuminations.com.pa`), navegar directo a `/gerencia` NO redirige a otra pantalla:
   la URL se queda en `/gerencia` y se ve el mensaje genérico **"Error al cargar los datos. Intenta
   recargar la página."** — sin ningún indicio de que es un tema de permisos. Verificado por
   network que el backend sí protege el dato real (`GET /api/gerencia/home` y `/api/gerencia/
   calendar` devuelven `403` los dos, sin fuga de datos). No es un hallazgo de seguridad (nada se
   filtra), pero si el criterio dice "ningún otro rol tiene acceso a la pantalla", hoy técnicamente
   sí "accede" a la ruta y ve una pantalla rota en vez de un bloqueo prolijo o una redirección.

## Estructural — bloqueado de origen, no se pudo verificar nada

- **SCRUM-167** (Salud Admin&Contab.), **SCRUM-169** (Salud Bodega), **SCRUM-170** (Salud
  Servicios): las 3 fichas de Jira dicen literalmente "RN1 a RN3/RN4: PENDIENTE DE DEFINIR" y
  "CRITERIOS DE ACEPTACION: PENDIENTE DE DEFINIR" — la ficha misma documenta que las pantallas de
  destino de esos indicadores no existen todavía. No hay Gherkin que ejecutar. Las 3 tarjetas SÍ
  renderizan en `/gerencia` con datos (Admin&Contab: "Facturas este mes 0 / Incobrables 0" — 2
  indicadores, no 3; Bodega: "Pedidos activos 20 / Entregados este mes 0" — 2, no 4; Servicios:
  "Abiertos 19 / Resueltos este mes 0 / Cancelados este mes 7" — 3, no 3 pero sin nombres RN)
  pero contra qué criterio comparar es indefinido por diseño. **Esto no debería haber llegado a
  `QA` sin que alguien primero termine de definir el criterio** — es la misma clase de hallazgo
  que Paso 0 de Pre-QA (dependencia de otro ticket/pantalla que no existe), solo que acá la
  dependencia es la definición del propio ticket.

## Lo que sí funciona

- **SCRUM-163** (regla de aprobación configurable) y **SCRUM-164** (Mi calendario/Outlook): 8/8
  tests del spec ya existente (`e2e/preqa-scrum163-164-gerencia-aprobaciones-calendario-20260822.spec.ts`)
  pasan limpio corridos hoy contra `dev.atlanticerp.ai` real (antes solo se habían corrido contra local).
  Incluye RBAC negativo real (403 forjando el request), creación/edición/borrado de regla con
  validación de campos obligatorios, y que el filtro de "Mi calendario" nunca manda `scope=team` ni
  `owner_id` (ver [[feedback_owner_id_team_membership_validation]]).
- **SCRUM-158** (Proyectos activos) — el valor (38) y el subtítulo ("en todos los módulos")
  coinciden exactamente con el único escenario que el ticket define.
- **SCRUM-172** (Agenda de Servicios) — "Ver detalle" navega correctamente a `/servicios/inicio`,
  a diferencia de su par de Bodega (hallazgo #6 arriba).
- El gate de permisos backend (403 real, sin fuga de datos) funciona para las 14 fichas por igual
  — el problema encontrado es solo de UX/routing en el frontend (hallazgo MEDIO #9), no de
  seguridad real.

---

## Siguiente paso sugerido (no ejecutado — epic de otro equipo)

No se transicionó ningún ticket ni se tocó código, por ser epic de otro equipo. Si Luis quiere que
esto se les traslade formalmente, falta decidir: (a) comentario en cada ticket de Jira con su
hallazgo puntual, o (b) un solo resumen centralizado. Cualquiera de las dos requiere OK explícito
antes de tocar Jira.
