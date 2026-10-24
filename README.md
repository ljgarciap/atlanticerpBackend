# AtlanticERP — Backend (CRM Atlantic)

API backend del CRM comercial para **Atlantic** (empresa de diseño y suministro de
iluminación). Reemplaza un mock HTML+Supabase con una plataforma real, **multi-tenant**,
construida como **monolito modular en Laravel 12 (PHP 8.3)**.

Dos módulos funcionales principales:
- **CRM** — pipeline de proyectos, actividades, documentos, directorio de contactos, reportes.
- **Installations (Servicios)** — casos de instalación y garantía, fotos de trabajo, asignación de electricistas.

El módulo **AI** integra la API de Claude de forma asíncrona vía Jobs, y el módulo **Auth**
maneja usuarios, roles y JWT. Ver el `CLAUDE.md` de la carpeta padre (`atlanticerp/CLAUDE.md`) para
el diseño completo de arquitectura, modelo de datos y convenciones del proyecto.

> Repo hermano: [`atlanticerp-frontend`](../atlanticerp-frontend) — SPA en React 18 + Vite + TypeScript,
> servida como build estático por Nginx en este mismo stack Docker.

---

## Stack

| Componente | Tecnología |
|---|---|
| Lenguaje / Framework | PHP 8.3 + Laravel 12 |
| Multi-tenancy | `spatie/laravel-multitenancy` — schema-per-tenant |
| Base de datos | PostgreSQL 16 (contenedor Docker) |
| Cache / Colas | Redis 7 (contenedor Docker) |
| Queue monitor | Laravel Horizon (contenedor separado) |
| Auth | JWT + bcrypt |
| Storage | S3 (Laravel Storage facade, disk `s3`) |
| Calidad de código | PHPStan Level 8 (Larastan) |
| Gateway local | Nginx (proxy `/api/*` a Laravel + estáticos de React) |

---

## Levantar el stack local

Requisitos: Docker y Docker Compose.

1. Copiar el archivo de variables de entorno y completarlo (ver sección siguiente):
   ```bash
   cp .env.example .env
   ```
2. Levantar los contenedores (Postgres, Redis, Laravel, Horizon, Nginx):
   ```bash
   docker compose -f infra/docker-compose.yml up -d --build
   ```
3. La API queda disponible a través de Nginx en `http://localhost:${NGINX_PORT:-8090}`
   (puerto configurable vía `NGINX_PORT` en `.env`; por defecto `8090`).
   - El endpoint de health check de Laravel está en `/up`.
   - El dashboard de Horizon está en `/horizon` (acceso fuera de `APP_ENV=local` restringido
     por `HORIZON_ADMIN_EMAILS`).

El contenedor `nginx` también sirve el build estático de `atlanticerp-frontend` (carpeta `dist/`
del repo hermano, montada como volumen) — para verlo es necesario haber corrido `npm run build`
en `atlanticerp-frontend` primero.

Existen además `infra/docker-compose.qa.yml` y `infra/docker-compose.prod.yml` para los
entornos de test y producción en AWS (no usados en desarrollo local).

---

## Variables de entorno

Las variables mínimas necesarias para levantar el entorno están documentadas en
[`.env.example`](.env.example) — úsalo como fuente de verdad y cópialo a `.env` antes de
levantar el stack. Cubre: configuración de la app, conexión a PostgreSQL, tenant por defecto,
colas/cache (Redis), storage S3, JWT y la API key de Anthropic (`ANTHROPIC_API_KEY`).

> Nota: nunca commitear `.env` ni credenciales reales. El archivo `.env.example` puede estar
> siendo actualizado en paralelo por otro agente/dev — revisa siempre la versión más reciente
> antes de levantar el entorno.

---

## Comandos comunes

```bash
# Migraciones (landlord + tenant, según el path configurado)
php artisan migrate

# Crear un tenant nuevo: crea sus 3 schemas Postgres, el registro landlord
# y corre las migrations del módulo tenant sobre ese tenant
php artisan tenant:create {slug} {nombre} [--domain=]

# Ejecutar un comando Artisan sobre uno o varios tenants
php artisan tenants:artisan {comando} --tenant={id}

# Tests
php artisan test

# Análisis estático (PHPStan Level 8 / Larastan)
vendor/bin/phpstan analyse
```

Estos comandos pueden ejecutarse dentro del contenedor `laravel` (`docker compose -f infra/docker-compose.yml exec laravel <comando>`) o localmente si tienes PHP 8.3 y las dependencias instaladas.

---

## Estructura de carpetas

```
app/
├── Modules/
│   ├── Auth/             usuarios, roles, JWT, refresh tokens
│   ├── CRM/               proyectos, pipeline, actividades, documentos, reportes
│   ├── Installations/     casos, fotos, comentarios, electricistas
│   └── AI/                integración con Claude API async vía Jobs
└── Shared/
    ├── Contracts/          DomainEvent.php — interfaz para eventos de dominio (hoy Event local, mañana Kafka)
    ├── Http/Middleware/     AuthenticateJwt, EnforceRole, InitializeTenancy
    ├── Multitenancy/        Tenant.php, SwitchTenantSchemaTask.php, TenantSchemaMigration.php
    └── Support/             CircuitBreaker.php

database/
└── migrations/
    ├── landlord/           tablas del esquema "landlord" (registro de tenants)
    └── tenant/              tablas replicadas por tenant (auth, crm, installations), prefijadas por módulo

routes/
├── auth.php
├── crm.php
├── installations.php
└── ai.php

infra/
├── docker-compose.yml          entorno de desarrollo local
├── docker-compose.qa.yml       entorno test en AWS
├── docker-compose.prod.yml     entorno producción en AWS
├── php/Dockerfile               imagen PHP-FPM 8.3 (pdo_pgsql, redis, zip, sodium, pcntl)
└── nginx/default.conf           proxy a Laravel + sirve estáticos de React
```

---

## Multi-tenancy: schema-per-tenant

Cada tenant tiene **3 schemas de PostgreSQL**, todos con el mismo prefijo (`slug` del tenant):

```
{tenant}_auth            → módulo Auth (usuarios, refresh tokens)
{tenant}_crm              → módulo CRM (proyectos, actividades, documentos, contactos)
{tenant}_installations    → módulo Installations (casos, fotos, comentarios)
```

El primer tenant es `atlantic` (`TENANT_DEFAULT` en `.env`). Al crear un tenant nuevo con
`php artisan tenant:create {slug} {nombre}` se crean sus 3 schemas, se registra en la tabla
landlord de tenants y se corren sobre él las migrations de `database/migrations/tenant`.
El aislamiento de datos se garantiza a nivel de base de datos (schemas separados), no por
filtros en código.

---

## Calidad y pruebas

- Tests: `php artisan test` (suites en `tests/Feature` y `tests/Unit`).
- Análisis estático obligatorio: `vendor/bin/phpstan analyse` (Level 8 vía Larastan,
  configuración en `phpstan.neon`).

---

## Referencia

Diseño de arquitectura completo, modelo de datos, eventos de dominio, convenciones de
nomenclatura y reglas del equipo de agentes: ver `../CLAUDE.md` (carpeta padre `atlanticerp/`).

## CI/CD y despliegue

Antes de empujar a `dev`, `test` o `master` hay que correr el **gate local** (`infra/gate.sh`) y activar el hook
(`git config core.hooksPath .githooks`). `dev` y `test` solo despliegan; `master` corre el CI completo en GitHub
Actions. Procedimiento completo, errores comunes y deploy manual: [`docs/runbooks/ci-cd-gate-local.md`](docs/runbooks/ci-cd-gate-local.md).
