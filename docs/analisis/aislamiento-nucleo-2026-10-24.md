# Análisis de aislamiento del núcleo genérico — Atlantic ERP

Fecha: 2026-10-24 · Estado: **borrador para decisión de Luis** · Roles: Analista + Arquitecto
Base de medición: ramas `sync/binax-snapshot-2026-10-22` (PR backend #1, frontend #1) y la base local de Atlantic recién sembrada.

> Este documento **no cambia código**. Mide el sistema, propone una clasificación y deja las decisiones de negocio
> a Luis (regla del workspace). Las cifras salen de scripts reproducibles (anexo); donde es una estimación, se dice.

---

## 1. Objetivo y criterio

Atlantic es un fork de binax, un ERP hecho para un cliente concreto (iluminación, Panamá, ERP legado ICG). La política
es **generalizar**: sacar todo lo que sea de una persona, un cliente o un país concreto, y dejar un núcleo que se
configure "a medida" (módulos, procesos, permisos ortogonales por rol y por usuario).

Cada pieza se clasifica en una de tres categorías:

| Categoría | Qué significa | Destino |
|---|---|---|
| **N — Núcleo** | Sirve a cualquier negocio con el mismo flujo | Se queda, sin cambios de concepto |
| **V — Variante configurable** | Existe una necesidad genérica pero su implementación depende de país, sector o proveedor | Se queda detrás de una interfaz y un ajuste (ya hay patrones así) |
| **E — Específico** | Pertenece al cliente, a su ERP anterior o a una persona | Se saca o se aísla en un paquete opcional |

## 2. Inventario medido

**Backend** (Laravel, PHP 8.3, multitenancy por schema de Postgres): 15 módulos, 2.415 líneas de rutas en 15 archivos,
95 comandos de consola, 20 jobs, 12 mails, 3 migraciones landlord + **440 migraciones de tenant**, ~209 tablas
en 10 schemas, 6.360 tests.

| Módulo | Archivos app | Tests | Tablas (schema) | Rutas (líneas) |
|---|---:|---:|---:|---:|
| AdminCont (facturación, cobros, caja) | 242 | 87 | 42 | 561 |
| Legacy (sincronización con ICG) | 173 | 98 | 32 | 49 |
| VentasDiseno (cotización, pipeline, pedidos) | 136 | 56 | 34 | 366 |
| Servicios (tickets, técnicos, herramientas) | 97 | 30 | 24 | 340 |
| Bodega (picking, inventario, entregas) | 91 | 53 | 21 | 267 |
| Compras (órdenes, proveedores, reposición) | 86 | 34 | 19 | 279 |
| Auth (usuarios, roles, MFA, auditoría) | 76 | 66 | 21 | 95+36+20+18 |
| CRM | 49 | 14 | 10 | 125 |
| Integrations (Lafayette, Outlook) | 14 | 10 | — | — |
| AI · Biblioteca · Notifications · Gerencia · Soporte · Lumo | 46 | 24 | 4+2 | 64+25 |

**Frontend** (React + Vite + TypeScript): 256 archivos de test, 3.216 tests. Páginas por área: admin-contab 31,
compras 16, ventas-diseno 13, bodega 11, servicios 10, security 7, y 34 páginas sueltas en la raíz.
Componentes: servicios 60, admin-contab 31, compras 21, security 13, bodega 12.

## 3. Mapa de procesos (cadena de valor)

```
CRM / Pipeline ──► Cotización ──► Pedido ──► Bodega: picking ─► entrega ─► Admin&Cont: factura ─► cobro
(VentasDiseno)     (versionado,    (órdenes)   (reservas, zona    (guía        (fiscal electrónica,    (abonos, saldo a
                    condiciones)               libre, ajustes)    firmada)     notas de crédito)       favor, compensación)
                                     │
                                     └─► Compras: reposición ─► orden de compra ─► ingreso (GoodsReceipt) ─► Bodega
Servicios: ticket (instalación/garantía/retrofit) ─► técnico ─► informe ─► cotización de servicio ─► Admin&Cont
Gerencia: aprobaciones, reglas, home   ·   Comisiones: arquitectos / vendedores / técnicos / cartera
Transversales: Auth/seguridad, Notificaciones, AI (análisis mensual), Biblioteca (manuales), Auditoría
```

Procesos **N** (cualquier negocio con ventas e inventario): pipeline→cotización→pedido, picking/entrega, compras,
tickets de servicio, caja chica, cobros, aprobaciones, notificaciones, auditoría.
Procesos **V**: facturación fiscal electrónica (cambia por país), impuestos, zona libre/aduana, comisiones.
Procesos **E**: sincronización con ICG, integración Lafayette Lighting, Lumo/CotizaYA/Tickets.

## 4. Acoplamiento medido a cliente, país y personas

Conteo de archivos con al menos una mención (backend: `app`, `routes`, `database`, `resources`, `lang`, `config`,
`tests`; frontend: `src`, `e2e`). Son **cotas altas**: algún término cuenta falsos positivos (p. ej. "PAC" o "RUC"
dentro de otro texto). Sirven para ordenar prioridades, no como cifra exacta.

| Concepto | Backend (archivos / menciones) | Frontend | Dónde se concentra |
|---|---:|---:|---|
| Lafayette / ICG | 364 / 2.054 | 63 / 370 | Legacy (119), tests (61), migraciones (59) |
| Fiscal Panamá (DGI, ITBMS, PAC, MSL, RUC) | 325 / 3.545 | 122 / 1.191 | AdminCont (87), tests (96), migraciones (46) |
| Zona Libre de Colón | 118 / 852 | 78 / 1.125 | Compras (18), Bodega (13), migraciones (24) |
| Dominio iluminación (luminaria, riel, showroom) | 127 / 558 | 79 / 391 | Sobre todo tests y seeders |
| Comisiones de arquitectos / cartera | 52 / 238 | 29 / 121 | AdminCont (20) |
| Integraciones propias (Lumo, CotizaYA, Tickets, Biblioteca) | 72 / 777 | 44 / 599 | VentasDiseno (21), Biblioteca (11) |
| Personas por nombre (residuo) | 312 / 1.017 | 254 / 1.051 | tests (104), migraciones (57), e2e (88) |
| Moneda/impuesto fijo (B/., 7%) | 56 / 147 | 19 / 26 | AdminCont, tests |

Lectura: **lo fiscal de Panamá y lo de Lafayette/ICG son el grueso del acoplamiento.** Las personas por nombre quedan sobre
todo en tests, migraciones históricas y prosa de documentos; el residuo en código vivo es pequeño pero existe.

Constantes de negocio: se encontraron 54 `const` candidatas. La mayoría son `DEFAULT_*` con su tabla de ajustes detrás
(`servicios_settings`, `bodega_settings`, `crm_settings`, `compras.settings`…), que es el patrón correcto. **Las que rompen la
regla del workspace** (umbral fijo en código): `ITBMS_RATE_LOCAL = 0.07` (Compras/GoodsReceiptController),
`DEFAULT_ITBMS_PERCENT = 7` (Servicios), `STAGNANT_THRESHOLD_DAYS = 15` (PipelineCard), `STALE_CONTACT_DAYS = 60`
(ClientsService), `CRITICAL_DAYS_POR_APROBAR = 3` (PurchaseOrder), `DIAS_UMBRAL_COMISION_PENDIENTE = 10`. Ya existen tablas
`itbms_rates`, `commission_tiers`, `cartera_commission_tiers`, `sales_commission_tiers` para lo fiscal y las comisiones.

## 5. Seguridad

Qué hay y es **N** (se queda): JWT con refresh rotatorio, MFA, bloqueo y throttle de login, reset de contraseña por correo
cifrado en cola (SES), cierre del registro público, «Ver como usuario» (impersonación de solo lectura, con auditoría
atribuida al actor real), sesiones de soporte, auditoría por usuario a nivel de BD, detección de anomalías, enlaces
compartidos con expiración, aislamiento por tenant (schema), y escaneo de seguridad en CI (Semgrep y `security-audit.yml`).

Riesgos a vigilar:
- El gate de revisores del Environment `prod` sigue sin configurar (decisión previa de Luis: pospuesto).
- `security_levels.permissions` (escape hatch JSONB) **no se filtra por visibilidad de módulo**: un permiso fino en un nivel
  compartido se filtra a todos sus roles. Regla ya documentada: antes de escribir ahí, verificar que ningún otro rol
  comparta el nivel (las migraciones de esta sincronización lo verifican por código).
- Quedan rutas con `role:` por nombre (`routes/users.php`, `security-levels.php`, `departments.php`, `ventas-diseno.php`
  en dashboard y órdenes, `gerencia.php`): usan solo `superadmin`/`management`/`lider_compras`, que existen en el catálogo, pero
  atan la ruta a un nombre de rol y no a un permiso.

## 6. Permisos

Modelo de **dos ejes ortogonales, ambos datos administrables** (ADR-006): `role` decide qué módulos alcanza
(`roles` + `role_module_visibility`) y `security_level` qué puede hacer (`security_levels` + permisos por módulo + JSONB fino),
más `extra_permissions` por usuario como excepción nominal.

Estado medido en la base real: 14 roles, 15 niveles, 7 módulos de negocio como datos (`modules`), **56 permisos** en el
catálogo (ventas_diseno 17, servicios 9, admin_contab 8, crm 5, compras 5, bodega 3, y 1–2 por security, reports, settings,
superadmin, ai, lumo, manuales).

| Rol | Nivel | Módulos visibles |
|---|---:|---|
| superadmin | 10 | todos (permiso `superadmin.all`) |
| management | 9 | los 7, con alcance de equipo |
| lider_admin_contab / asistente_administrativa | 15 / 13 | admin_contab |
| lider_servicios / tecnico_servicios / garantias_servicios | 14 / 5 / 11 | servicios |
| lider_compras / lider_operaciones | 16 / 1 | compras (+ operaciones) |
| lider_bodega / asistente_bodega / ayudante_general_bodega / transporte | 4 / 3 / 3 / 3 | bodega |
| vendedor_disenador | 6 | admin_contab, servicios, ventas_diseno |

Hallazgos:
1. **Los roles llevan el vocabulario del cliente** (`vendedor_disenador`, `ayudante_general_bodega`, `lider_operaciones`,
   `garantias_servicios`). Son datos (se pueden renombrar por CRUD), pero hoy están enlazados por clave en rutas, tests y seeders.
2. **Hay un hueco en los niveles**: existen 1–11 y 13–16; el 12 no existe (viene de la numeración de las migraciones).
   Inofensivo, pero conviene renumerar al limpiar.
3. **Módulos sin entrada en `modules`**: `crm` reutiliza la visibilidad de `ventas_diseno`; `lumo` y `manuales` solo
   tienen permisos en el catálogo. Falta decidir si son módulos o permisos sueltos.
4. **Permisos de persona**: `SpecialPermissionSeeder` queda vacío a propósito; los tests reciben las concesiones desde
   `FixtureSpecialPermissionSeeder` (solo tests). Cualquier permiso nuevo de este tipo debe decidirse como de **rol**
   (va al nivel) o de **excepción** (va a `extra_permissions`).
5. **Frontend duplica lógica de rol** en `Sidebar.tsx` y `ToolsPanel.tsx`: pendiente migrarlos a `user.permissions`.
6. Pendiente **Fase E** del ADR-006: `UserService::approve()` aún asigna `user_roles` (enum legado) y no `role_id`.
   Es un cambio destructivo de BD y necesita decisión explícita de Luis.

## 7. Clasificación propuesta por módulo

| Módulo / pieza | Cat. | Razón | Acción recomendada |
|---|:--:|---|---|
| Auth, seguridad, auditoría, usuarios, roles, niveles | N | Idéntico para cualquier negocio | Quedarse; cerrar Fase E y las rutas con `role:` |
| Notificaciones, Gerencia (aprobaciones) | N | Transversales | Quedarse |
| CRM / Pipeline / Cotizaciones | N | Flujo comercial estándar | Quedarse; sacar términos de iluminación de seeders y textos |
| Pedidos / Bodega (picking, entregas, inventario) | N | Logística estándar | Quedarse; separar reglas de **Zona Libre** como variante |
| Compras (OC, proveedores, reposición, sustitutos) | N | Estándar | Quedarse; Zona Libre → variante; mover `ITBMS_RATE_LOCAL` a tasas |
| Servicios (tickets, técnicos, herramientas) | N/V | El flujo es genérico; los tipos (instalación, retrofit, garantía) son del sector | Hacer los tipos de ticket un catálogo |
| Admin&Cont: cobros, caja chica, arqueo, comisiones | N/V | Genérico; las **comisiones** (arquitectos/cartera) son del negocio | Mantener; las tablas de tramos ya son datos |
| **Admin&Cont: facturación fiscal electrónica** | **V** | Depende del país; ya existe `PacProviderContract` + `PacProviderFactory` (Digifact, MSL) | Dejar como paquete fiscal del país; el núcleo solo habla con la interfaz |
| **Legacy (sincronización con ICG)** | **E** | Migración desde el ERP anterior del cliente: 173 archivos, 32 tablas, 98 tests | Aislar como paquete opcional; no es parte del núcleo |
| **Integrations/Lafayette** | **E** | Integración con un proveedor concreto | Aislar con Legacy |
| Integrations/Outlook (correo) | N/V | Integración de correo genérica | Quedarse como adaptador |
| AI (análisis mensual, uso/costos) | N/V | Genérico; el prompt del dominio es del sector | Quedarse; revisar prompts |
| Lumo, Soporte, Biblioteca, CotizaYA | E/V | Productos hermanos del cliente | Decidir caso por caso (ver sección 9) |
| Seeders, e2e y tests con datos de iluminación | E | Datos de ejemplo del cliente | Reemplazar por un dataset demo genérico |

## 8. Riesgos y dependencias de aislar

- **Legacy está enredado en el núcleo**: 67 clases de Legacy referenciadas desde `AppServiceProvider`, 10 migraciones del
  tenant, rutas de Ventas&Diseño y comandos de consola. Sacarlo exige primero poner interfaces o *no-ops* en esos puntos.
- **440 migraciones de tenant son historia inmutable** y muchas crean tablas `legacy_*` y fiscales de Panamá. Aislar no
  puede borrarlas sin decidir si el esquema de Atlantic nace limpio (sin historial) o hereda todo.
- **Lo fiscal está repartido**: 87 archivos de AdminCont, 46 migraciones, 122 archivos del frontend. Aunque haya contrato de
  proveedor, la UI, los PDF (QR, clave de acceso) y las validaciones de RUC/DV están pensados para DGI Panamá.
- **El frontend importa vocabulario del cliente en i18n** (es/en) y en e2e (88 archivos con nombres de personas).
- **Sincronizar con binax en adelante**: cada paso de aislamiento se aleja de binax y encarece la próxima sincronización.
  Hay que decidir hasta cuándo se sigue sincronizando (ver pregunta 3).
- **Cobertura de tests**: aislar cambia contratos; los 6.360 tests son la red de seguridad, pero muchos tienen personas y
  datos de iluminación incrustados y requieren generalizarse antes.

## 9. Decisiones de Luis (2026-10-24)

| # | Pregunta | Decisión | Consecuencia |
|---|---|---|---|
| 1 | Vertical de Atlantic | **ERP genérico multi-sector** | El dominio de iluminación (riel, showroom, luminarias) sale del núcleo; los catálogos (productos, tipos de ticket, vocabulario) pasan a ser configurables |
| 2 | País | **Multi-país** | El fiscal de Panamá deja de ser el fiscal: queda como **un paquete más** sobre `PacProviderContract`; zona horaria y moneda se vuelven ajustes del tenant |
| 3 | ¿Seguir sincronizando con binax? | **Solo seguridad y correcciones** | Se pueden refactorizar libremente los módulos; de binax solo se traerán parches de seguridad y bugs, cherry-pick por cherry-pick |
| 4 | Productos hermanos (Lumo, Soporte, Biblioteca, CotizaYA, Tickets) | **Sacarlos del todo** | Se eliminan del código, rutas, permisos (`lumo.view`, `manuales.manage`), tablas, i18n y tests |
| 5 | Vocabulario de roles | **Renombrar a genéricos** | Cambio de datos + claves en rutas, tests, seeders y manuales; hay que fijar el catálogo genérico (ver 11) |
| 6 | Fase E del ADR-006 | **Autorizada, dentro del aislamiento** | Se retira el enum legado `user_roles` y `UserService::approve()` pasa a asignar `role_id`; migración reversible |

## 10. Plan ajustado a las decisiones

| Fase | Contenido | Entregable | Riesgo |
|---|---|---|---|
| 0 | Merge de la sincronización con binax (PR #1 de ambos repos) y CI en verde | Base única y verificada | Bajo |
| 1 | **Sacar los hermanos** (Lumo, Soporte, Biblioteca, CotizaYA, Tickets) y **limpiar personas y umbrales fijos** sin cambiar comportamiento; rutas con `role:` y `Sidebar`/`ToolsPanel` a permisos | Núcleo sin hermanos, personas ni umbrales fijos | Bajo-medio |
| 2 | **Fase E**: retirar `user_roles`; `approve()` asigna `role_id` | Un solo mecanismo de roles | Medio (destructivo, reversible) |
| 3 | **Renombrar roles** al catálogo genérico (datos + claves en código, tests y seeders) | Vocabulario neutro | Medio |
| 4 | **Dataset demo genérico** (sin iluminación ni personas) en seeders, e2e e i18n | Demo multi-sector | Medio |
| 5 | **Aislar Legacy e Integrations/Lafayette** como paquete opcional detrás de interfaces (hoy el núcleo referencia 67 de sus clases) | Paquete opcional o eliminado | Medio-alto |
| 6 | **Paquete fiscal por país**: Panamá como primer paquete; zona libre y comisiones como variantes; moneda y zona horaria por tenant | Fiscal enchufable | Alto (UI y PDF) |
| 7 | **Catálogos de vocabulario** (tipos de ticket, categorías) configurables por tenant | Multi-sector real | Medio |

Orden: 1→2→3 en seguida (bajo acoplamiento y desbloquean el resto); 4 antes de 5-7 para tener tests y demo neutros con los que verificar.

## 11. Pendientes de detalle antes de la Fase 3 (roles)

- Fijar el **catálogo genérico de roles** (propuesta a validar con Luis): `vendedor_disenador→vendedor`,
  `ayudante_general_bodega→auxiliar_bodega`, `lider_operaciones→lider_operaciones` (ya genérico), `garantias_servicios→tecnico_garantias`,
  `tecnico_servicios→tecnico`, el resto (`lider_*`, `asistente_*`, `management`, `superadmin`) se quedan.
- Decidir si el **módulo `operaciones`** y la separación `management`/`superadmin` se mantienen.
- Regla de ejecución: cada fase pasa por **spec aprobada → Senior Reviewer → QA**; ninguna se implementa sin spec.

## Anexo — cómo se midió (reproducible)

- Inventario por módulo: conteo de archivos `.php` en `app/Modules/<M>`, tests por ruta, `information_schema.tables` por schema,
  `wc -l routes/*.php`.
- Acoplamiento: script que recorre `app|routes|database|resources|lang|config|tests` (backend) y `src|e2e` (frontend) y cuenta
  archivos y menciones por expresión regular; el área se toma del módulo o del primer segmento de ruta.
- Seguridad y permisos: consultas directas a `atlantic_auth.roles`, `role_module_visibility`, `security_levels`, `modules` y
  `permission_catalog` en la base local sembrada.
- Constantes de negocio: `grep` de `const *_(PORCENT|THRESHOLD|MARGIN|TASA|RATE|TIER|UMBRAL|DIAS|LIMIT)* = <número>` en `app/`.
- Herramientas de sincronización: `erp/scripts/upstream-sync/` (ver README).
