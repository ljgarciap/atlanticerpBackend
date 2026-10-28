# Spec — Fase 11: flujos de negocio completos en el entorno local

**Estado**: alcance AMPLIADO por Luis (2026-10-28): cubrir **todos** los flujos y resumir cómo funcionan hoy, porque pueden cambiar o mejorar. Decisiones en §5.
**Origen**: «lo que nos importa en primera instancia es probar el desarrollo local y ajustarlo antes de desplegarlo» (2026-10-28). El humo de las fases 6 a 9 ya existe; falta ejercitar las **cadenas de negocio** de punta a punta.

## 1. Hechos verificados (BD local de desarrollo, 2026-10-28)
| Tabla | Filas | Lectura |
|---|---|---|
| `ventas_diseno.quotes` / `quote_items` | 3 / **0** | cotizaciones sin ninguna línea: no se puede avanzar ninguna |
| `ventas_diseno.pipeline_cards` / `sales_projects` | 12 / 8 | alcanzan |
| `bodega.orders` / `order_items` | 5 / 5 | hay pedidos para picking |
| `admin_contab.invoices` / `payments` / `credit_notes` | 1 / **0** / **0** | sin cobros ni notas de crédito |
| `compras.purchase_orders` / `providers` | 1 / 3 | una sola OC; sin recepciones |
| `servicios.tickets` | 14 | alcanzan |
- Facturación: el proveedor por defecto es `none` (Fase 6b): se emite **documento interno**, sin transmitir a ninguna autoridad. Es el modo que se prueba en local.
- El recorrido por pantallas (5 roles, 44 pantallas) no encontró errores, pero con estos datos no ejercita ninguna transición.
- Cadena de venta, por endpoints reales: `POST ventas-diseno/quotes` → `…/parts` → `…/items` → `POST quotes/{id}/confirm` → `PATCH ventas-diseno/pipeline/{id}/stage` → (pedido en Bodega) `start-picking` → `complete-picking` → `dispatch` → `register-delivery` → `POST admin-contab/invoices` → `POST admin-contab/payments/cobro` → `PUT payments/{id}/confirm`.
- Cadena de compra: `POST compras/orders` → `PATCH …/approve` → `PATCH …/advance` → `POST compras/goods-receipts` → `…/lines/{id}/confirm` (sube el stock).
- Límites: nginx admite 10 conexiones simultáneas a la API (un solo worker de Playwright); los datos de facturas y cobros no se borran (inmutables por diseño).

## 2. Diseño (alcance ampliado)
Un **flujo** es un proceso de negocio con transiciones de estado (no un endpoint). El universo medido: **708 rutas de API (345 de escritura)** en 10 módulos; hay manuales técnicos por módulo heredados de binax (`docs/manuales-tecnicos/`), por verificar contra el código (uno es del módulo Soporte, ya eliminado).

Cada módulo entrega tres cosas:
1. **Catálogo de flujos «cómo funciona hoy»** (`docs/flujos/<modulo>.md`): por flujo, quién lo ejecuta (rol/permiso), pasos y endpoints, estados y transiciones, reglas de negocio y umbrales configurables, efectos en otros módulos (stock, cuentas por cobrar, notificaciones), y **qué se verificó ejecutándolo** frente a lo que solo se leyó del código. Cada flujo cierra con **«Candidatos a cambio o mejora»** (huecos, reglas rígidas, pasos manuales, deuda heredada), sin implementarlos.
2. **Flujo ejecutable** (`e2e/flujos/<modulo>.spec.ts`, `npm run e2e:flujos`, 1 worker): crea sus propios datos (prefijo «[FLUJO]» + marca de tiempo) por la API real con la cuenta de cada rol, verifica el **estado resultante** en cada paso y prueba los permisos cruzados (el rol incorrecto recibe 403). Capturas de las pantallas clave en navegador.
3. **Informe de hallazgos** (`docs/reviews/fase11-<modulo>-…`) con un PR pequeño y test de regresión por cada defecto corregido (como el de familias); lo que sea decisión de producto va al catálogo como candidato, no se cambia solo.

### Orden de entrega (un módulo por entrega)
| Sub-fase | Módulo | Flujos previstos (a confirmar al inventariarlos) |
|---|---|---|
| 11a | Ventas & Diseño + CRM | cotización (partes, líneas, versiones), confirmación, pipeline y compuertas, proyectos de venta, clientes/subclientes/contactos, arquitectos y comisiones, catálogo, proyectos CRM |
| 11b | Compras | OC (alta, aprobación por umbral, avance), recepción de mercancía, pagos a proveedor, logística, reposición, reclamos/garantías, Zona Libre, sustitutos |
| 11c | Bodega | pedidos (picking, despacho, entrega, guía firmada), inventario y ajustes, kardex, devoluciones, rutas del día, ubicaciones/reubicación, almacenes |
| 11d | Admin & Contab | facturación (documento interno), cobros/abonos/anulaciones, notas de crédito, comisiones internas/externas, caja chica, arqueo, cuentas bancarias, tesorería, reportes, configuración fiscal |
| 11e | Servicios | tickets por tipo (instalación, garantía, reclamo, retrofit), asignación a técnicos, informes de inspección, cotizaciones de servicio, insumos y herramientas, agenda |
| 11f | Transversales | autenticación (login, MFA, cambio obligatorio de clave), usuarios/roles/niveles/permisos adicionales, «Ver como usuario», notificaciones, catálogos y ajustes, perfil de empresa y región, gerencia, IA, auditoría |
Un **catálogo global** (`docs/flujos/README.md`) enlaza los módulos y consolida los candidatos a mejora, priorizados, para que Luis decida cuáles se convierten en fases.

## 3. Fuera de alcance
Transmisión fiscal real (DGI/MSL/Digifact), correo saliente real, AWS y despliegue, el adaptador de Colombia, rendimiento/carga. No se implementan mejoras de producto sin decisión de Luis: se registran.

## 4. Criterios de aceptación
1. Cada módulo tiene su catálogo de flujos, y cada afirmación está marcada como «verificado ejecutando» o «leído del código».
2. `npm run e2e:flujos` corre los flujos del módulo en verde contra el stack local, es repetible y verifica el estado resultante (no solo el código HTTP) y los permisos cruzados.
3. Todo defecto encontrado tiene PR con test de regresión; lo demás queda como candidato priorizado.
4. Textos de commits, PR, docs y datos de prueba sin nombres reales ni vocabulario de otro sector (contrato de neutralidad en verde).
5. Los manuales heredados se contrastan: lo que ya no es cierto se corrige o se marca.

## 5. Decisiones de Luis
1. Enfoque: flujos que crean sus propios datos. ✅ (2026-10-28)
2. Cadenas: **todos los flujos de todos los módulos**, con resumen de cómo funcionan hoy y candidatos a mejora. ✅ (2026-10-28)
3. Datos que dejan los flujos en la BD local: se dejan marcados «[FLUJO]» (la BD local se resetea con el ciclo habitual si se llena). *Por defecto, a confirmar.*
4. Entrega: un PR por módulo (catálogo + flujo ejecutable) y un PR pequeño por cada defecto corregido. *Por defecto, a confirmar.*
