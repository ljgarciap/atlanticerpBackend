# Documento de Arquitectura
## AtlanticERP — CRM Illuminations

**Versión:** 2.0
**Fecha:** 2026-06-16
**Estado:** Aprobado — en implementación (Fase 0 completada)
**Preparado por:** Equipo de Arquitectura — Softclass

> Esta versión reemplaza la v1.0 (microservicios Spring Boot + Kafka). El 2026-06-15
> se decidió pivotar a un monolito modular Laravel por velocidad de desarrollo —
> ver detalle en `[[project-stack-change-crm]]` (memoria del proyecto).

---

## 1. Resumen ejecutivo

Illuminations requiere una plataforma digital que centralice la gestión comercial de proyectos
de iluminación y la operación de su equipo de servicios en campo. La solución propuesta reemplaza
un sistema prototipo (HTML + Supabase) por una plataforma robusta, multi-tenant y preparada para
crecer con el negocio.

La arquitectura está diseñada bajo tres principios:

- **Velocidad de entrega:** monolito modular en Laravel — el equipo lo conoce, entrega 40-60% más rápido que un rediseño en microservicios para el alcance y plazo de este proyecto (CRM funcional en 3 meses)
- **Aislamiento lógico por tenant:** cada empresa cliente vive en su propio set de schemas PostgreSQL, sin compartir datos entre tenants
- **Camino abierto a microservicios:** los módulos se comunican vía eventos de dominio locales hoy; la interfaz `DomainEvent` permite migrar a un broker (Kafka u otro) en el futuro sin tocar la lógica de negocio

---

## 2. Alcance del sistema

### 2.1 Módulo CRM — Gestión Comercial

Centraliza el ciclo de vida de los proyectos de diseño y cotización de iluminación:

- **Pipeline de proyectos** con 6 etapas: Lead → En Diseño → En Cotización → Propuesta Enviada → Cerrado → Perdido
- **Bitácora de actividades** por proyecto: llamadas, emails, reuniones, WhatsApp, visitas
- **Gestión de documentos** enviados al cliente: cotizaciones, diseños, planos, renders, fichas técnicas
- **Recordatorios de seguimiento** con alertas por vencimiento y actividad fría
- **Directorio de contactos**: developers, arquitectos, encargados, clientes
- **Reportes mensuales** con métricas de desempeño por diseñador y del equipo completo
- **Asistente AI** (Claude API): resumen de cliente, sugerencia de próximas acciones, borrador de mensajes

### 2.2 Módulo Servicios — Operaciones en Campo

Gestiona los casos de instalación y garantía del equipo de electricistas:

- **Casos de trabajo** con estados: Pendiente → Asignado → En Proceso → Terminado → Verificado
- **Fotos del trabajo** etiquetadas (antes / durante / después) desde dispositivo móvil
- **Comentarios de seguimiento** por caso
- **Asignación de electricistas** con vista propia de sus casos activos e historial

### 2.3 Control de acceso por rol

| Rol | Acceso |
|---|---|
| Gerencia | Todo el sistema: CRM, Servicios, Reportes, Administración de usuarios |
| Diseñador / Vendedor | Sus propios proyectos CRM, directorio, reportes propios |
| Supervisor de Servicios | Módulo de servicios completo, equipo de electricistas |
| Electricista | Solo sus casos activos y su historial |

---

## 3. Arquitectura del sistema

### 3.1 Estilo arquitectónico

La plataforma adopta un **monolito modular multi-tenant**: una única aplicación Laravel
organizada en módulos de dominio (Auth, CRM, Installations, AI) que se comunican entre sí
mediante eventos de dominio locales. Cada tenant (empresa cliente) tiene su propio conjunto
de schemas PostgreSQL, aislados lógicamente dentro del mismo servidor de base de datos.

```
┌─────────────────────────────────────────────────────────────────────┐
│                         CLIENTES                                     │
│              Navegador web · Dispositivo móvil                       │
└───────────────────────────┬─────────────────────────────────────────┘
                            │  HTTPS
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    NGINX (reverse proxy)                             │
│   Sirve build estático de React · Proxy /api/* → Laravel             │
└───────────────────────────┬─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                  LARAVEL — monolito modular                          │
│        Middleware: AuthenticateJwt · EnforceRole · InitializeTenancy │
│  ┌──────────┐  ┌──────────────┐  ┌───────────────┐  ┌─────────────┐ │
│  │  AUTH    │  │     CRM      │  │ INSTALLATIONS │  │  AI MODULE  │ │
│  │ Módulo   │  │   Módulo     │  │   Módulo      │  │             │ │
│  │          │  │              │  │               │  │ Claude API  │ │
│  │ Usuarios │  │ Proyectos    │  │ Casos         │  │ (Anthropic) │ │
│  │ JWT      │  │ Actividades  │  │ Fotos         │  │ vía Jobs    │ │
│  │ Roles    │  │ Documentos   │  │ Comentarios   │  │ async       │ │
│  │ bcrypt   │  │ Reportes     │  │ Electricistas │  │             │ │
│  └────┬─────┘  └──────┬───────┘  └───────┬───────┘  └──────┬──────┘ │
│       │               │                  │                 │        │
│       └───────────────┴────── DomainEvent (local) ─────────┘        │
└───────────────────────────┬─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│              LARAVEL HORIZON (colas — Jobs async)                    │
│   AI requests · notificaciones · reportes pesados                    │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
          ┌────────────────────┼────────────────────┐
          ▼                    ▼                    ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│  PostgreSQL     │  │   AWS S3        │  │     Redis       │
│  schema-per-    │  │  (documentos    │  │  (colas,        │
│  tenant: auth · │  │   y fotos)      │  │   caché,        │
│  crm·installs   │  │                 │  │   tokens)       │
└─────────────────┘  └─────────────────┘  └─────────────────┘
```

### 3.2 Contenedores Docker (5)

| Contenedor | Responsabilidad |
|---|---|
| **nginx** | Punto de entrada único. Sirve el build estático de React y enruta `/api/*` a Laravel — actúa como API Gateway, sin componente AWS adicional |
| **laravel** | Aplicación monolítica: módulos Auth, CRM, Installations, AI |
| **horizon** | Procesa colas Redis: jobs de IA, notificaciones, reportes pesados |
| **postgres** | Base de datos, schema-per-tenant |
| **redis** | Colas, caché, revocación de tokens |

---

## 4. Stack tecnológico

### 4.1 Backend

| Componente | Tecnología | Justificación |
|---|---|---|
| Lenguaje | PHP 8.3 | Compatibilidad con Laravel 12 y el ecosistema de paquetes usados |
| Framework | Laravel 12 | Subido desde Laravel 11 el 2026-06-15 por CVE-2026-48019 (CRLF injection) sin parche en toda la rama 11.x |
| Build | Composer | Gestión de dependencias estándar del ecosistema PHP |
| Multi-tenancy | spatie/laravel-multitenancy (schema-per-tenant) | Aislamiento lógico por tenant sin instancias de BD separadas |
| Base de datos | PostgreSQL 16 en contenedor Docker | EBS dedicado — no RDS en fase inicial, por escala y presupuesto |
| Colas / Jobs | Laravel Queue + Redis + Horizon | Procesamiento async de IA, notificaciones y reportes |
| Storage | AWS S3 (filesystem disk `s3`) | Documentos y fotos, presigned URLs de corta duración |
| Autenticación | JWT + bcrypt | Stateless, sin sesión en servidor; contraseñas con hash seguro |
| Caché / colas | Redis | Revocación de tokens, caché de datos frecuentes, backend de colas |
| Análisis estático | PHPStan Level 8 (Larastan) | Obligatorio en CI — sin errores en el scaffold inicial |
| Mensajería futura | Eventos de dominio locales hoy (`App\Shared\Contracts\DomainEvent`) — Kafka diferido | Permite migrar a un broker real cuando existan microservicios que lo justifiquen, sin reescribir lógica de negocio |

### 4.2 Frontend

| Componente | Tecnología | Justificación |
|---|---|---|
| Framework | React 18 + Vite + TypeScript | SPA moderna, tipado estricto, build ultrarrápido |
| Estado del servidor | TanStack Query v5 | Caché inteligente, sincronización automática, optimistic updates |
| Estado de UI | Zustand | Liviano, sin boilerplate, suficiente para una SPA de este tamaño |
| Formularios | React Hook Form + Zod | Validación tipada end-to-end, rendimiento optimizado |
| Gráficas | Chart.js 4 | Misma librería del prototipo — sin curva de aprendizaje adicional |
| Estilos | Tailwind CSS | Consistencia de diseño, tokens de marca configurados globalmente |

### 4.3 Infraestructura

| Ambiente | Tecnología | Notas |
|---|---|---|
| Desarrollo local | Docker (build vía `laravel-scaffold-tmp`, no Herd) | Consistencia con el despliegue en contenedores |
| CI/CD | GitHub Actions | CI en todos los pushes. CD solo en push a `dev`/`test`/`master` |
| Dev / Test / Prod | 3 instancias EC2 t3.small clonadas de una AMI base (`atlanticerp-base`) | Una por entorno: `dev.atlanticerp.ai`, `test.atlanticerp.ai`, `atlanticerp.ai` — ver `[[project-dominio-infraestructura]]` |
| Despliegue | Clonar repo + `docker compose build` en el host EC2 | Justificado por escala de monolito en servidor único — no se usa ECR/registro de imágenes |

---

## 5. Base de datos

Se utiliza **un único servidor PostgreSQL con schemas separados por tenant**, no por servicio.
Esto permite, en el futuro, dar de alta nuevas empresas cliente sin tocar código — solo
provisionando un nuevo set de schemas.

```
PostgreSQL
└── tenant: illuminations (primer tenant)
    ├── schema: illuminations_auth          → usuarios, tokens
    ├── schema: illuminations_crm           → proyectos, actividades, documentos, contactos
    └── schema: illuminations_installations → casos, fotos, comentarios
```

Las migraciones se gestionan con el sistema de migraciones de Laravel, prefijadas por módulo
y ejecutadas dentro del schema del tenant correspondiente (`spatie/laravel-multitenancy`).

---

## 6. Comunicación entre módulos

### 6.1 Comunicación síncrona (HTTP interno)

Para operaciones que requieren respuesta inmediata: login, crear proyecto, consultar datos.
El cliente llama a Nginx, que enruta `/api/*` a Laravel; dentro de Laravel, los módulos
se invocan directamente (mismo proceso, mismo deploy).

### 6.2 Comunicación asíncrona (eventos de dominio locales)

Para operaciones que pueden procesarse en segundo plano: análisis de AI,
notificaciones, actualizaciones de métricas, sincronización entre módulos.
Hoy se implementan como **Laravel Events** locales detrás de la interfaz `DomainEvent`;
el día que se necesite un broker real (Kafka u otro), solo cambia la implementación,
no la lógica de negocio que dispara o consume el evento.

```
Módulo A  →  dispara DomainEvent  →  Listener (mismo proceso, hoy)
                                          │
                              mañana: Listener → publica a broker externo
```

**Principales eventos de dominio:**

| Evento | Disparado por | Consumido por |
|---|---|---|
| `crm.project.stage-changed` | Módulo CRM | Módulo AI (sugerencias), métricas |
| `crm.document.uploaded` | Módulo CRM | Registro de auditoría |
| `installations.case.assigned` | Módulo Installations | Notificación al electricista |
| `installations.case.completed` | Módulo Installations | Flujo de verificación |
| `ai.analysis.requested` | CRM / Installations | Módulo AI (Job async vía Horizon) |
| `ai.analysis.completed` | Módulo AI | CRM / Installations (guarda resultado) |
| `auth.user.created` | Módulo Auth | CRM e Installations (inicializa perfil) |

### 6.3 Manejo de fallos en Jobs

Los Jobs fallidos (p. ej. llamada a Claude API caída) se reintentan con backoff y, tras agotar
los reintentos configurados, se registran en la tabla `failed_jobs` de Laravel para análisis
manual, sin bloquear el resto de la cola.

---

## 7. Seguridad

### 7.1 Autenticación y autorización

- Todo acceso a `/api/*` requiere Bearer JWT, excepto `POST /api/auth/login` y `GET /api/health`
- El JWT contiene `user_id` y `rol` — los módulos los leen sin volver a consultar la BD en cada request
- Los **refresh tokens** se almacenan hasheados en PostgreSQL (vida útil configurable vía `REFRESH_TOKEN_EXPIRATION_DAYS`)
- La revocación de tokens (logout, cambio de contraseña) se propaga vía Redis

### 7.2 Autorización por rol a nivel API

La validación de permisos ocurre en middleware de Laravel (`EnforceRole`), no solo en la interfaz.
Un electricista que intente consultar proyectos de otro usuario recibe `403 Forbidden`
independientemente de lo que muestre el frontend.

### 7.3 Almacenamiento de archivos

Los documentos y fotos no se sirven directamente — se generan **presigned URLs** de corta duración
que permiten al cliente descargar el archivo directamente desde S3 sin exponer credenciales ni
pasar el archivo por el backend.

### 7.4 Secretos

Ninguna credencial (API keys, contraseñas de BD, JWT secret) está en el código fuente.
Todas se inyectan como variables de entorno por ambiente (`.env` por instancia, nunca versionado).

---

## 8. Resiliencia

| Patrón | Descripción | Ejemplo de aplicación |
|---|---|---|
| **Circuit Breaker** | Si Claude API falla repetidamente, se abre el circuito y se retorna fallback sin seguir intentando | `filippi/php-circuit-breaker` envolviendo las llamadas del módulo AI |
| **Retry con backoff** | Reintenta Jobs fallidos con espera incremental | Reintento de llamada a Claude API ante error transitorio |
| **Idempotencia** | Cada Job/evento es seguro de procesar más de una vez | Esencial para reintentos automáticos en Horizon |
| **Health checks** | `GET /api/health` expone estado de la app | Monitoreo externo y balanceo futuro |
| **Backup** | `pg_dump` diario a S3 + snapshot EBS semanal vía AWS Backup | Recuperación ante pérdida de datos o de instancia |

---

## 9. Infraestructura AWS

La plataforma corre en 3 instancias EC2 t3.small idénticas (clonadas de una AMI base
`atlanticerp-base`), una por entorno. Detalle completo de IPs, DNS y costos en
`[[project-dominio-infraestructura]]`.

| Entorno | Dominio | Uso |
|---|---|---|
| Dev | `dev.atlanticerp.ai` | Validación del CTO antes de pasar a test |
| Test | `test.atlanticerp.ai` | Validación del cliente |
| Prod | `atlanticerp.ai` / `www.atlanticerp.ai` | Producción 24/7 |

SSL se gestiona con Certbot, ejecutado por separado en cada instancia (sin certificado compartido).
CloudWatch + AWS Backup + EventBridge (auto-stop de dev/test fuera de horario) quedan pendientes
de configurar — ver tarea abierta en el tablero del proyecto.

---

## 10. Roadmap de implementación

| Fase | Contenido | Resultado visible |
|---|---|---|
| **Fase 0** — Setup | Repos, infraestructura AWS, scaffold Laravel 12 + PHPStan, scaffold frontend | ✅ Completada 2026-06-16 |
| **Fase 1** — Fundación | Estructura de módulos, DomainEvent, multi-tenancy, Docker Compose completo | Login funcional con todos los roles, infraestructura local levantando con un comando |
| **Fase 2** — CRM Core | Módulo CRM: proyectos, pipeline Kanban, actividades | Pipeline operativo, creación y seguimiento de proyectos |
| **Fase 3** — CRM Completo | Documentos (S3), directorio de contactos, reportes mensuales | Feature-parity completa con el prototipo en el módulo CRM |
| **Fase 4** — Servicios | Módulo Installations: casos, fotos, electricistas | Módulo de instalaciones y garantías operativo |
| **Fase 5** — AI | Módulo AI con Claude API, Jobs async vía Horizon | Asistente de IA con circuit breaker, análisis ejecutivos |
| **Fase 6** — Observabilidad | CloudWatch, métricas, alertas operacionales | Visibilidad completa del sistema en producción |

---

## 11. Estructura del proyecto

Dos repos separados dentro de `atlanticerp/` — ver detalle completo de carpetas en `atlanticerp/CLAUDE.md`:

```
atlanticerp/
├── CLAUDE.md                      Guía del proyecto y equipo de desarrollo
├── docs/
│   ├── arquitectura.md            Este documento
│   └── aws-ec2-setup-walkthrough.md
├── atlanticerp-backend/                 repo Laravel 12 (Grupo-Lafayette/atlanticerp-backend)
└── atlanticerp-frontend/                repo React + Vite + TypeScript (Grupo-Lafayette/atlanticerp-frontend)
```

---

## 12. Decisiones de arquitectura registradas

| Decisión | Detalle |
|---|---|
| Monolito modular sobre microservicios | Velocidad de entrega para el plazo de 3 meses — equipo conoce Laravel, no Spring Boot/Kafka |
| spatie/laravel-multitenancy schema-per-tenant | Aislamiento lógico sin instancias de BD separadas |
| S3 con SDK nativo de Laravel | Filesystem disk `s3`, sin capa de abstracción adicional |
| PostgreSQL schemas separados por tenant (no por módulo) | Permite alta de nuevos tenants sin tocar código |
| JWT stateless sobre sesiones en servidor | Sin estado compartido entre instancias |
| React + Vite sobre Next.js | SPA sin SSR, backend es la API |
| Kafka diferido indefinidamente | Se retoma cuando existan microservicios reales que lo justifiquen — abstracción `DomainEvent` ya preparada |
| Despliegue por clonado de repo + build en el host (no ECR) | Justificado por escala de monolito en servidor único |

---

*Documento actualizado por el equipo de arquitectura de Softclass tras el pivote de stack del 2026-06-15.*
