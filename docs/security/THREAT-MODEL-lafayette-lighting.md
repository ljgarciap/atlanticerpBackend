# Threat Model — Integración Lafayette Lighting (épica SCRUM-969)

Status: Implemented — revisión post-implementación (código ya en dev/test/prod al momento de este
documento, ver `docs/pre-qa/lafayette-zona-libre-20260926.md` para el historial de validación
funcional). Cybersecurity revisa una superficie ya construida — Non-negotiable del rol: leer el
modelo de datos y auth real antes de señalar un gap, no revisar en el vacío.

## Qué se protege, de quién

- **Integridad del catálogo de AtlanticERP** (`ventas_diseno.catalog_products`) — de que un tercero (o
  alguien que comprometa la cuenta de Lafayette) inserte/sobrescriba datos incorrectos o
  maliciosos en un sistema que alimenta cotizaciones, ventas y reportes reales.
- **3 juegos de credenciales** (dev/test/prod) que AtlanticERP usa para autenticarse contra un sistema
  externo — de exposición que permita a un tercero no autorizado leer el catálogo de Lafayette
  suplantando a AtlanticERP, o de que alguien use esas credenciales para pivotear.
- **Disponibilidad de un job de negocio** (Bodega Zona Libre) — de que un tercero caído, lento o
  con datos corruptos tumbe procesos internos de AtlanticERP (memoria, conexiones DB, el propio HTTP
  request si un usuario abre esa pantalla).
- **No hay PII ni datos financieros de terceros** en esta integración — es un catálogo de
  productos (nombre, marca, categoría, foto, precio de lista, stock). Amenaza de negocio, no de
  cumplimiento regulatorio.

Modelo de amenaza: no hay razón para asumir que Lafayette es un actor hostil — es un proveedor real
del mismo grupo (dominio `grupolafayette.com`, ver contexto de la sesión que originó este ticket).
El riesgo real es (a) un MITM en la conexión saliente por Internet público, (b) una fuga de
credenciales de nuestro lado (Jira, `.env`, logs), y (c) errores/cambios inesperados del lado de
Lafayette que AtlanticERP debe tolerar sin caerse.

## Superficie técnica revisada

- Conexión saliente TLS desde los 3 EC2 hacia `app.grupolafayette.com:3306` (Internet público, a
  diferencia de `legacy_icg` que viaja por túnel VPN privado — ver `config/database.php`).
- Extractor de solo lectura (`App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor`) —
  un único `SELECT` explícito de 9 columnas, nunca `INSERT`/`UPDATE`/`DELETE` contra la fuente.
- Comando programado (`lafayette:sync-productos`, consola únicamente, sin endpoint HTTP expuesto).
- Certificado de CA de Lafayette versionado en el repo (`storage/certs/lafayette-ca.pem`).
- Credenciales en `.env` de cada servidor (mismo tratamiento que `LEGACY_ICG_DB_*`/`ANTHROPIC_API_KEY`).

## Threat model (STRIDE)

| # | Amenaza | Control existente | Estado | Quién lo implementa | Cómo se valida |
|---|---|---|---|---|---|
| S1 | Spoofing — MITM se hace pasar por el servidor de Lafayette | TLS con verificación de identidad real vía `MYSQL_ATTR_SSL_CA` apuntando a la CA propia de Lafayette (`lafayette-ca.pem`), no un pinning de conveniencia — su huella SHA256 coincide exacto con la que Lafayette compartió por el correo original (canal separado de donde se extrajo el cert) | ✅ Mitigado | Backend Dev (ya implementado) | `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=true`, confirmado en las 3 conexiones reales (dev/test/prod) |
| T1 | Tampering — datos alterados en tránsito | TLS (integridad + cifrado) | ✅ Mitigado | — | mismo control que S1 |
| T2 | Tampering — Lafayette (o una cuenta comprometida del lado de ellos) inyecta HTML/script en `name`/`brand`/`category` (XSS/HTML-injection almacenado) | React escapa por default en todo el frontend (grep confirmó **cero** usos de `dangerouslySetInnerHTML` en el proyecto); los PDFs Blade tampoco usan `{!! !!}` sin escapar cerca de estos campos | ✅ Mitigado, sin control explícito nuevo necesario | — | verificado por grep en esta revisión, no es una suposición |
| T3 | Tampering — colisión de `reference` sobrescribe silenciosamente un producto de otro origen (ya materializado en la primera corrida real: 11 productos de "Zona Libre de Colón"/Leviton fueron reasignados) | `CatalogProductLoader` distingue `provider_id` null (vincula) vs. no-null (coexiste, nunca pisa) — encontrado y corregido por Pre-QA antes de llegar a producción | ✅ Mitigado (post-incidente real en dev, corregido antes de promover) | Backend Dev + Pre-QA | test `test_reference_de_otro_proveedor_real_coexiste_sin_pisar`, ver `CatalogProductLoader.php` |
| R1 | Repudiation — no se puede reconstruir qué cambió y cuándo en una corrida | `audit_logs` (vía `LogsAuditTrail` en `CatalogProduct`) + `lafayette_sync_runs` (creados/actualizados/vinculados/coexistentes/errores por corrida) + `AuditService::log()` en cada corrida real | ✅ Mitigado | Backend Dev (ya implementado) | usado activamente durante Pre-QA para reconstruir el estado "antes" de los 11 productos afectados |
| I1 | Information Disclosure — **las 3 contraseñas (dev/test/prod) están en texto plano en la descripción del ticket SCRUM-969 en Jira**, pegadas junto al correo original del proveedor | Ninguno todavía | 🔴 **NO mitigado — hallazgo real, sigue abierto** | Luis (rotación con Lafayette) + quien edite el ticket | — |
| I2 | Information Disclosure — certificado de CA commiteado en el repo | N/A — es información pública (un certificado no es secreto, cualquiera que se conecte al servidor lo puede obtener), evaluado correctamente como no sensible | ✅ No es un riesgo | — | — |
| I3 | Information Disclosure — niveles de precio del proveedor (`precio_1..7`) expuestos más allá de lo necesario | El Extractor solo hace `SELECT` de `precio_5`, ni siquiera trae `precio_1/2/3/4/6/7` en la query — minimización de datos ya aplicada (no fue diseñada explícitamente por seguridad, pero cumple el principio) | ✅ Mitigado de hecho | — | ver `ProductoExtractor::query()` |
| D1 | Denial of Service — Lafayette caído/lento bloquea el job programado en cascada | `CircuitBreaker` (Redis) alrededor del `extract()` — abre el circuito tras fallos repetidos, no reintenta indefinidamente. **Confirmado en producción real**: outage transitorio de Lafayette observado por Pre-QA (~02:29–03:03 UTC del re-check), el circuito lo absorbió sin caer en cascada | ✅ Mitigado, validado con un incidente real, no solo en teoría | Backend Dev (ya implementado) | observado en vivo durante Pre-QA |
| D2 | Denial of Service — cargar el catálogo completo (25,816+ filas) en memoria agota el proceso PHP-FPM | Encontrado como bug real por Pre-QA (memory exhaustion, 500 sin log) tras el primer fix de UI — corregido paginando en SQL para el caso sin `search`; `memory_limit` subido a 256M como red adicional | ✅ Mitigado para el caso común | Backend Dev + Pre-QA | `WarehouseController::show()`, validado con curl real contra dev/test |
| D3 | Denial of Service (residual, documentado, no bloqueante) — `search` de texto libre SIN ningún filtro de categoría/ubicación sigue cargando el candidate set completo en memoria para el fuzzy match | Ninguno explícito más allá del `memory_limit=256M` | 🟡 Riesgo residual conocido, aceptado | Backend Dev (si se prioriza) | documentado en el propio código (`WarehouseController.php`) |
| D4 | Denial of Service — 2 corridas manuales simultáneas del comando podrían competir por el límite de conexiones del proveedor (3-5 simultáneas) | `withoutOverlapping()` a nivel de scheduler protege las corridas programadas; **no hay lock explícito contra 2 disparos manuales simultáneos** — Pre-QA reprodujo una colisión real, capturada limpiamente como `error` en el reporte gracias al índice único parcial de Postgres como backstop, **sin filas duplicadas** | 🟡 Mitigado por backstop de BD, no por diseño explícito | Backend Dev (si se prioriza) | reproducido por Pre-QA con 2 procesos concurrentes reales |
| E1 | Elevation of Privilege — el usuario `lighting_ext_*` podría tener más privilegios de los que Lafayette declara ("solo lectura") | Fuera de nuestro control técnico directo (es la cuenta que ELLOS administran) — mitigación de nuestro lado: el código propio JAMÁS emite `INSERT`/`UPDATE`/`DELETE` contra esa conexión, solo `SELECT` explícito | 🟡 Confianza en la palabra del proveedor + control de que nuestro código nunca escribe ahí | N/A (control del lado de Lafayette) | verificado por lectura de código: `ProductoExtractor` solo usa `->select()` |
| E2 | Elevation of Privilege — el comando `lafayette:sync-productos` se dispara desde un endpoint HTTP expuesto | No existe tal endpoint — es un comando de consola únicamente, solo alcanzable con SSH al servidor | ✅ No es un riesgo | — | verificado: sin rutas HTTP que lo invoquen |

## Hallazgos que requieren acción (no silenciados, escalados)

### 🔴 CRÍTICO — I1: credenciales expuestas en texto plano en Jira (SCRUM-969)
No lo remedio yo mismo (regla del rol: nunca ejecuto remediación destructiva o de alto impacto sin
Luis). Ya escalado verbalmente al inicio de esta épica, sigue sin resolverse. Recomendación
concreta:
1. Pedirle a Lafayette rotar las 3 contraseñas (`lighting_ext_dev/test/prod`) — ya circularon en un
   sistema no diseñado para secretos.
2. Redactar la descripción del ticket SCRUM-969 en Jira una vez rotadas (dejar el resto de la spec
   intacto).
3. Actualizar `.env` de los 3 servidores con las contraseñas nuevas una vez Lafayette las rote.

Esto no bloquea el estado "Implemented" de este threat model — es una acción operativa de Luis, no
una falla de diseño del código.

### 🟡 MEDIO — D4: sin lock explícito entre corridas manuales concurrentes
No bloqueante — el backstop de BD (índice único parcial) ya demostró que previene corrupción real,
solo produce un `error` reportado en vez de silencio. Si se quiere hardening, la opción de menor
esfuerzo es un lock de aplicación (`Cache::lock('lafayette-sync')`) alrededor de `handle()` del
comando — decisión de Backend Dev/Arquitecto si se prioriza, no urgente.

### 🟡 MEDIO — D3: `search` sin filtro en catálogo grande
Mismo criterio — riesgo conocido, mitigado parcialmente (memory_limit), no bloqueante para el
estado actual. Si Lafayette crece significativamente el catálogo, revisar de nuevo.

## Handoff

- **A Luis**: decisión y ejecución de I1 (rotación + redacción del ticket) — es la única acción de
  este documento que no puede resolver un agente del equipo.
- **A Backend Dev/Arquitecto** (si se prioriza, ninguno bloqueante): D3 y D4, ambos ya documentados
  en el propio código con la mitigación parcial existente.
- **A Senior Reviewer**: sin cambios de código pendientes de este documento — esta revisión es
  posterior a la implementación ya aprobada y desplegada.
- **A AWS Architect**: sin necesidad de servicios AWS nuevos (GuardDuty/WAF/Macie) para esta
  integración puntual — el volumen y sensibilidad de datos no lo justifica; el `CircuitBreaker` +
  CloudWatch (`Nginx5xxCount`, ya existente) ya cubrieron en la práctica la detección real del
  incidente D2 durante esta misma épica.

## Costo/esfuerzo de lo pendiente

| Hallazgo | Esfuerzo | Urgencia |
|---|---|---|
| I1 — rotar credenciales + redactar Jira | Bajo (coordinación con Lafayette, sin código) | Alta — exposición activa acumulando tiempo |
| D4 — lock de aplicación | Bajo (una línea, `Cache::lock`) | Baja — backstop de BD ya funciona |
| D3 — acotar candidate set de `search` sin filtro | Medio (cambiaría el comportamiento de fuzzy search compartido por 7 bodegas) | Baja — solo relevante si el catálogo crece mucho más |
