# Runbook — Protección de host (CrowdSec + bouncer nftables + fail2ban)

**Script:** `infra/scripts/install-crowdsec.sh` · **Estado real al 2026-09-23:** solo `dev.atlanticerp.ai`
la tiene. `test.atlanticerp.ai` y `atlanticerp.ai` NO (servicios inactivos, sin `cscli`).
**Diseño original:** `docs/adr/005-modsecurity-crowdsec.md` del workspace AtlanticERP
(`Illumination/atlanticerp/docs/adr/`, no en este repo) — ver "Discrepancias" abajo.

## Qué se instala (idéntico a dev, salvo lo marcado)

| Pieza | Versión | Detalle |
|---|---|---|
| CrowdSec (motor + API local `127.0.0.1:8080`) | 1.7.8 | repo apt de packagecloud, clave GPG con huella fijada (`6A89E3C2…D0C`) |
| `crowdsec-firewall-bouncer-nftables` | 0.0.34 | modo `nftables`, hooks `input` **y `forward`** (Docker publica por FORWARD), `DROP`, bans de 4 h (perfil por defecto) |
| fail2ban | 1.1.0 | jail `sshd` por defecto del paquete Ubuntu: `maxretry 5`, `findtime/bantime 600`, backend systemd |
| Colecciones | — | `base-http-scenarios`, `http-cve`, `http-dos`, `linux`, `nginx`, `sshd`, `whitelist-good-actors` |
| Adquisición | — | `nginx-file.yaml` → `/var/log/nginx-crowdsec/access.log` (bind mount del servicio `nginx`); `auth.log` (sshd); `syslog`/`kern.log` |
| Logrotate | — | `infra/logrotate/atlanticerp-nginx-crowdsec` (copytruncate, 7 días) |

**Diferencias deliberadas respecto a dev** (dev no las tiene):
1. Escenario `crowdsecurity/http-admin-interface-probing` **en simulación** desde el primer día.
2. Allowlist `atlanticerp-infra` (Elastic IP del entorno, IP privada, CIDR de la VPC, IPs del equipo) y
   `ignoreip` equivalente en fail2ban (`/etc/fail2ban/jail.d/atlanticerp-ignoreip.local`).
3. Guardas de arranque (ver "Cómo funciona") y modo `--observe`.

## Por qué el escenario admin-interface-probing va en simulación

La API de AtlanticERP vive bajo `/api/admin/*`. El escenario cuenta cualquier 403/404 a una ruta que
contenga `/admin` (la lista de CrowdSec incluye `/admin`, subcadena de `/api/admin/…`) y banea la
IP al llegar a **3 rutas distintas en 10 s** — en **todos los puertos** (443 y 22 a la vez), porque
el bouncer actúa sobre el firewall del host. Incidente 2026-09-23 en dev: 3 baneos de ~40 min a una
IP de trabajo durante Pre-QA de SCRUM-945 (los 403/404 eran las respuestas correctas de las pruebas
de permisos). Un usuario legítimo puede llegar al mismo umbral: sin permiso, pantalla que consulte
varias rutas admin, QA con pruebas negativas, o **varias personas detrás de la misma IP de oficina**
(el conteo es por IP de origen, no por usuario). En simulación el escenario sigue detectando y
alertando, pero no crea bans. Los demás escenarios (CVE, archivos sensibles, DoS, fuerza bruta SSH)
quedan activos.

> Medición en prod (2026-09-23, ~8 archivos de log, 18 522 solicitudes): **0** respuestas 403/404
> en `/api/admin/*`. El uso normal hoy no se acerca al umbral; el riesgo es estructural.

## Orden de despliegue

**0. Requisitos previos (una vez, antes de tocar prod)**
- Acceso alterno a la instancia que NO dependa de SSH (consola serie de EC2 o SSM Session Manager)
  y probado. Es el plan B si el firewall corta SSH.
- Memoria: el motor ocupa ~305 MB (+12 bouncer, +21 fail2ban) y las t3.small tienen 2 GB **sin swap**
  (dev quedó con ~340 MB *available*). El script exige 700 MB *available* (`--min-avail-mb`).
  Revisar `free -m` y la alarma de memoria de CloudWatch de la instancia antes de instalar.
- Dos sesiones SSH abiertas durante la instalación.
- Confirmar el nombre real del bouncer y que `infra/logrotate/atlanticerp-nginx-crowdsec` esté junto al
  script (el script lo copia desde el checkout).

**1. Dry-run local** (sin root, sin SSH, no toca nada): `bash infra/scripts/install-crowdsec.sh test --dry-run`

**2. test primero**
```bash
K=~/.ssh/atlanticerp/atlanticerp-key.pem
ssh -i $K ubuntu@test.atlanticerp.ai 'mkdir -p /tmp/atlanticerp-crowdsec/infra/scripts /tmp/atlanticerp-crowdsec/infra/logrotate'
scp -i $K infra/scripts/install-crowdsec.sh ubuntu@test.atlanticerp.ai:/tmp/atlanticerp-crowdsec/infra/scripts/
scp -i $K infra/logrotate/atlanticerp-nginx-crowdsec ubuntu@test.atlanticerp.ai:/tmp/atlanticerp-crowdsec/infra/logrotate/
ssh -i $K ubuntu@test.atlanticerp.ai          # sesión 1 (dejar una 2ª abierta en otra terminal)
sudo bash /tmp/atlanticerp-crowdsec/infra/scripts/install-crowdsec.sh test --dry-run
sudo bash /tmp/atlanticerp-crowdsec/infra/scripts/install-crowdsec.sh test --team-ip <IP_EQUIPO> --allow-current-ssh-ip
```
Alternativa: si `test` ya tiene este commit desplegado, ejecutarlo desde `/var/www/backend`
(`sudo infra/scripts/install-crowdsec.sh test ...`).

**3. Verificar en test durante ≥ 24 h**: sección "Verificación". Provocar a propósito 3 403/404 en
rutas `/api/admin/*` distintas y confirmar que NO hay ban (solo alerta simulada).

**4. prod, primera vez en `--observe`** (recomendado)
```bash
sudo bash install-crowdsec.sh prod --dry-run
sudo bash install-crowdsec.sh prod --observe --allow-current-ssh-ip
```
`--observe` deja **todo** el motor en simulación: detecta y registra, no genera ningún ban. Revisar a
las 72 h: `sudo cscli alerts list --since 72h` — ¿hay alertas sobre IPs de oficina/usuarios reales
(`http-crawl-non_statics`, `http-probing`, `http-bad-user-agent`, etc.)? Si no hay falsos positivos:
```bash
sudo cscli simulation disable --global                         # sale de observe (global; sin --global solo imprime la ayuda)
sudo cscli simulation enable crowdsecurity/http-admin-interface-probing   # este SIGUE en simulación
sudo systemctl reload crowdsec && sudo cscli simulation status
```
Verificar con `cscli simulation status` que el escenario admin quedó en simulación y el resto activo
(la semántica de `exclusions` invierte según el modo global: comprobarlo, no asumirlo).

**5. prod sin `--observe`** solo tras esas 72 h limpias. IPs del equipo en prod: **no por defecto**
(una IP allowlisteada nunca se banea aunque se comprometa); el script advierte si se pasa `--team-ip`
en prod.

## Cómo funciona (anti-lockout)

1. `policy-rc.d` impide que `apt install` arranque servicios: el bouncer no puede aplicar nada antes
   de tiempo.
2. Orden: paquetes → config → **motor** → **allowlist** → bouncer → **guarda** → arranque del bouncer.
3. Guarda: antes de arrancar el bouncer espera 20 s a la primera descarga de la lista comunitaria y
   comprueba con `cscli decisions list --ip` que ninguna IP allowlisteada ni con sesión SSH abierta
   tenga un ban. Si lo tiene, **no arranca el bouncer** y aborta.
4. Avisa si tu IP SSH actual no está cubierta (`--allow-current-ssh-ip` la agrega 24 h con expiración).
5. Rangos amplios (`< /16`, ej. `0.0.0.0/0`) se rechazan como IP de equipo.
6. Idempotente: re-ejecutable; no duplica adquisiciones, colecciones ni allowlist.

## Verificación

```bash
systemctl is-active crowdsec crowdsec-firewall-bouncer fail2ban        # 3x active
sudo cscli metrics show acquisition                                    # nginx-crowdsec/access.log con líneas leídas
sudo cscli bouncers list                                               # firewall-bouncer-<env> validated, last pull reciente
sudo cscli simulation status                                           # escenario admin (o "global simulation: enabled" en --observe)
sudo cscli allowlists inspect atlanticerp-infra
sudo nft list table ip crowdsec
sudo cscli alerts list --since 24h; sudo cscli decisions list
free -m                                                                # available >= ~300 MB tras instalar
```
El script corre estas comprobaciones al final (`[9/9]`). Nota: `cscli explain` **ignora** la simulación
(corre su propio runtime; verificado en dev), así que el script lo usa solo como información, no como
prueba de que no se banea; la prueba es `cscli simulation status` + la prueba en vivo del paso 3.

## Rollback (1 minuto)

```bash
sudo systemctl stop crowdsec-firewall-bouncer     # deja de aplicar bans YA
sudo cscli decisions delete --all
sudo nft delete table ip crowdsec; sudo nft delete table ip6 crowdsec6
sudo systemctl disable --now crowdsec-firewall-bouncer crowdsec
sudo fail2ban-client unban --all                  # y, si hace falta: sudo systemctl disable --now fail2ban
```
Si SSH ya está cortado: consola serie de EC2 / SSM (no EC2 Instance Connect: usa SSH). El bouncer
solo bloquea; no toca la app ni nginx, así que detenerlo restaura el tráfico de inmediato.

## Riesgos de lockout / operación en prod

1. **CD por SSH desde GitHub Actions:** los runners tienen IPs dinámicas y amplias; no se pueden
   allowlistear. Si una IP de runner estuviera en la lista comunitaria, el deploy fallaría. Con el CI
   caído hoy se usa `infra/deploy-manual.sh` desde una IP conocida. Vigilar tras reactivar el CI.
2. **IP compartida de oficina:** todos los usuarios de Atlantic detrás de un mismo NAT suman sus
   respuestas 4xx. Por eso el paso `--observe` de 72 h antes de activar bans en prod.
3. **Otros escenarios HTTP** (`http-crawl-non_statics`, `http-probing`, `http-bad-user-agent`) podrían
   confundir una SPA con muchas llamadas API con un crawler. Solo se puede descartar observando
   tráfico real (paso 4).
4. **Memoria sin swap:** ~340 MB más en una t3.small; un pico mata procesos (OOM) en vez de degradar.
   Considerar swap o subir de tipo si `available` queda < 300 MB.
5. **Lista comunitaria (CAPI):** puede incluir IPs de clientes/ISP compartidos; monitorear
   `cscli decisions list` las primeras 48 h.
6. **Sin acceso alterno probado** = el peor caso (lockout total). No instalar en prod sin haberlo
   probado.
7. **Instalación de paquetes de terceros en prod:** repo `packagecloud.io`; el script verifica la
   huella de la clave GPG y fija las versiones. `unattended-upgrades` no actualiza repos de terceros
   por defecto; actualizar CrowdSec debe ser una decisión explícita.

## Discrepancias detectadas (CLAUDE.md / ADR-005 vs realidad en dev, 2026-09-23)

| Se decía | Realidad |
|---|---|
| CLAUDE.md "Seguridad de infraestructura (Epic 11)": fail2ban + CrowdSec + ModSecurity "en los 3 EC2" | fail2ban y CrowdSec **solo en dev**. test/prod: servicios inactivos, sin `cscli`; prod sin paquetes. Solo ModSecurity (`DetectionOnly`) está en prod |
| ADR-005 `Status: Implemented` | Implementado solo en dev; el propio ADR tiene una sección "Próximo paso" que dice que nada toca infraestructura viva (texto previo a la actualización 2026-07-05) |
| ADR-005: CrowdSec lee logs con adquisición **Docker** nativa | Real: adquisición de **archivo** sobre `/var/log/nginx-crowdsec/access.log` (bind mount de `infra-nginx-1`); la Docker no cargó (ya lo dice CLAUDE.md) |
| ADR-005/CLAUDE.md: "sin colección de SSH, fail2ban es la autoridad" | `crowdsecurity/sshd` **está instalada y activa** (reconocido en CLAUDE.md como redundancia conocida); además `linux`, `base-http-scenarios` y `whitelist-good-actors` |
| CLAUDE.md apunta a `docs/adr/005-modsecurity-crowdsec.md` | No existe en `atlanticerp-backend/docs/adr/`; vive en `Illumination/atlanticerp/docs/adr/005-modsecurity-crowdsec.md` (workspace) |
| — | Dev **no tiene allowlist** (`cscli allowlists list` vacío) y su fail2ban no tiene `ignoreip`; el script las agrega para test/prod. Conviene aplicar lo mismo a dev |
| — | `simulation.yaml` de dev fue modificado el 2026-09-23 (escenario admin en simulación) — coherente con este script |
| ADR-005/CLAUDE.md dicen "Paranoia Level 1 en los 3" para ModSecurity | No verificado en esta tarea (fuera de alcance) |
