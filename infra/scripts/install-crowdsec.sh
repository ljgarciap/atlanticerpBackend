#!/bin/bash
# install-crowdsec.sh — protección de HOST de AtlanticERP (CrowdSec + bouncer nftables + fail2ban) para
# test.atlanticerp.ai y atlanticerp.ai. Replica lo que HOY solo tiene dev.atlanticerp.ai (leído en solo-lectura el
# 2026-09-23: crowdsec 1.7.8, crowdsec-firewall-bouncer-nftables 0.0.34, fail2ban 1.1.0 con los
# defaults del paquete Ubuntu). Runbook: docs/runbooks/crowdsec-host-protection.md
#
# Uso (se ejecuta EN el servidor, como root; copiar este script Y infra/logrotate/atlanticerp-nginx-crowdsec):
#   sudo infra/scripts/install-crowdsec.sh <test|prod> [--dry-run] [--team-ip IP]...
#        [--allow-current-ssh-ip] [--min-avail-mb N] [--observe] [--yes]
#   ATLANTICERP_TEAM_IPS="1.2.3.4 5.6.7.8" también sirve en vez de --team-ip.
#
# ── ROLLBACK (1 minuto, si algo sale mal o hay lockout) ─────────────────────────────────────────
#   sudo systemctl stop crowdsec-firewall-bouncer          # deja de aplicar bans YA
#   sudo cscli decisions delete --all                      # borra todas las decisiones activas
#   sudo nft delete table ip crowdsec; sudo nft delete table ip6 crowdsec6   # si quedaron reglas
#   sudo systemctl disable --now crowdsec-firewall-bouncer crowdsec          # apagado definitivo
#   sudo fail2ban-client unban --all; sudo systemctl disable --now fail2ban  # (si fail2ban molesta)
#   Bloqueado por SSH: entrar por consola serie de EC2 / SSM (NO por EC2 Instance Connect: también
#   usa SSH y el firewall lo corta) y correr lo de arriba. Confirmar que ese acceso alterno existe
#   ANTES de correr este script en prod.
# ────────────────────────────────────────────────────────────────────────────────────────────────
#
# Qué NO hace: no toca nginx, contenedores ni la app; no hace reload de nginx; no modifica la
# configuración de ModSecurity (sigue DetectionOnly). Solo agrega defensa a nivel de host.
#
# Decisiones de diseño (todas explicadas más abajo en su paso):
#  - Los servicios NO arrancan durante `apt install` (policy-rc.d): primero se deja lista la
#    allowlist, y el bouncer (el único que puede cortar tráfico) arranca ÚLTIMO.
#  - Antes de arrancar el bouncer se verifica que ninguna IP "nuestra" ya tenga un ban pendiente.
#  - `crowdsecurity/http-admin-interface-probing` queda en SIMULACIÓN desde el primer día.
#  - Idempotente: cada paso comprueba el estado actual antes de cambiar nada.
set -euo pipefail

# ── configuración ──────────────────────────────────────────────────────────────────────────────
CROWDSEC_VERSION="1.7.8"
BOUNCER_VERSION="0.0.34"
MIN_AVAIL_MB=700   # ver nota de memoria abajo
# Huella de la clave GPG del repo apt de CrowdSec (packagecloud), leída del keyring real de dev.
APT_KEY_FPR="6A89E3C2303A901A889971D3376ED5326E93CD0C"
APT_KEYRING="/etc/apt/keyrings/crowdsec_crowdsec-archive-keyring.gpg"
ALLOWLIST_NAME="atlanticerp-infra"
NGINX_LOG="/var/log/nginx-crowdsec/access.log"
COLLECTIONS="crowdsecurity/base-http-scenarios crowdsecurity/http-cve crowdsecurity/http-dos crowdsecurity/linux crowdsecurity/nginx crowdsecurity/sshd crowdsecurity/whitelist-good-actors"
# Escenario en simulación: la API propia de AtlanticERP vive bajo /api/admin/*; un 403/404 legítimo ahí
# (usuario sin permiso, pruebas negativas de QA/Pre-QA) cuenta como "escaneo de paneles admin" y
# 3 rutas distintas en 10 s banean la IP en TODOS los puertos (incidente 2026-09-23 en dev: 3 cortes
# de ~40 min seguidos a una IP de trabajo). En simulación sigue detectando y registrando alertas,
# pero no genera decisiones de ban. Se revierte con: cscli simulation disable <escenario>.
SIM_SCENARIO="crowdsecurity/http-admin-interface-probing"

# NOTA DE MEMORIA: en dev el motor ocupa ~305 MB RSS (+12 MB bouncer, +21 MB fail2ban) y las
# t3.small tienen 2 GB SIN swap (dev quedó con ~340 MB "available" tras instalarlo). Por eso el
# umbral es 700 MB *available* (deja ~350 MB tras instalar), no los 400 MB genéricos.

DRY=0
ASSUME_YES=0
ALLOW_CURRENT_SSH=0
OBSERVE=0
ENV_NAME=""
TEAM_IPS=()
for _ip in ${ATLANTICERP_TEAM_IPS:-}; do TEAM_IPS+=("$_ip"); done

usage() {
  cat >&2 <<'EOF'
Uso: sudo install-crowdsec.sh <test|prod> [--dry-run] [--team-ip IP]... [--allow-current-ssh-ip]
                              [--min-avail-mb N] [--observe] [--yes]
  --dry-run               solo imprime lo que haría (no requiere root ni toca nada)
  --team-ip IP            IP del equipo a allowlistear (repetible; también ATLANTICERP_TEAM_IPS="a b")
  --allow-current-ssh-ip  allowlistea 24 h las IPs con sesión SSH abierta ahora (anti-lockout)
  --min-avail-mb N        umbral de memoria available (default 700)
  --observe               TODO el motor en simulación (detecta y alerta, no genera bans): recomendado en
                          prod las primeras 72 h para revisar falsos positivos con `cscli alerts list`
  --yes                   no pedir confirmación escrita en prod
Rollback y detalles: encabezado del script y docs/runbooks/crowdsec-host-protection.md
EOF
  exit 1
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    test|prod) ENV_NAME="$1" ;;
    dev) echo "dev ya tiene esta protección instalada; este script no lo toca." >&2; exit 1 ;;
    --dry-run) DRY=1 ;;
    --yes) ASSUME_YES=1 ;;
    --allow-current-ssh-ip) ALLOW_CURRENT_SSH=1 ;;
    --observe) OBSERVE=1 ;;
    --team-ip) shift; TEAM_IPS+=("${1:?--team-ip requiere una IP}") ;;
    --min-avail-mb) shift; MIN_AVAIL_MB="${1:?--min-avail-mb requiere un número}" ;;
    -h|--help) usage ;;
    *) echo "Argumento desconocido: $1" >&2; usage ;;
  esac
  shift
done
[[ -n "$ENV_NAME" ]] || usage

case "$ENV_NAME" in
  test) EIP="34.204.207.240" ;;
  prod) EIP="18.215.30.125" ;;
esac
BOUNCER_NAME="firewall-bouncer-${ENV_NAME}"

# ── helpers ────────────────────────────────────────────────────────────────────────────────────
say()  { echo "==> $*"; }
info() { echo "    $*"; }
warn() { echo "WARN: $*" >&2; }
die()  { echo "ERROR: $*" >&2; exit 1; }

# Escribe stdin en $1 con modo $2 (solo si cambió). En --dry-run solo lo anuncia (nunca imprime el
# contenido: puede llevar la api_key del bouncer).
put_file() {
  local dest="$1" mode="$2" tmp
  tmp="$(mktemp)"
  cat > "$tmp"
  if (( DRY )); then
    info "[dry-run] escribiría $dest (modo $mode)"
    rm -f "$tmp"
    return 0
  fi
  if [[ -f "$dest" ]] && cmp -s "$tmp" "$dest"; then
    info "= $dest sin cambios"
  else
    install -D -m "$mode" -o root -g root "$tmp" "$dest"
    info "+ $dest escrito"
    FILE_CHANGED=1
  fi
  rm -f "$tmp"
}
FILE_CHANGED=0

valid_ip() {
  local v="$1" prefix
  [[ "$v" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?$ ]] || return 1
  if [[ "$v" == */* ]]; then
    prefix="${v##*/}"
    # Una allowlist con un rango enorme (ej. 0.0.0.0/0) desactivaría la protección por completo.
    (( prefix >= 16 )) || return 1
  fi
  return 0
}

is_private_ip() {
  case "$1" in
    10.*|127.*|192.168.*) return 0 ;;
    172.1[6-9].*|172.2[0-9].*|172.3[0-1].*) return 0 ;;
  esac
  return 1
}

imds() { # metadata de EC2 con IMDSv2
  local token
  token="$(curl -fsS -m 3 -X PUT http://169.254.169.254/latest/api/token \
    -H 'X-aws-ec2-metadata-token-ttl-seconds: 60' < /dev/null)" || return 1
  curl -fsS -m 3 -H "X-aws-ec2-metadata-token: $token" \
    "http://169.254.169.254/latest/meta-data/$1" < /dev/null
}

# IPs con una sesión SSH establecida ahora mismo (para no dejarte fuera a ti mismo).
current_ssh_ips() {
  ss -Htn state established '( sport = :22 )' 2>/dev/null \
    | awk '{print $4}' | sed 's/:[0-9]*$//' | sort -u
}

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
POLICY_RC="/usr/sbin/policy-rc.d"
POLICY_OWNED=0
EXPLAIN_TMP=""
cleanup() {
  if (( POLICY_OWNED )); then rm -f "$POLICY_RC"; fi
  if [[ -n "$EXPLAIN_TMP" ]]; then rm -f "$EXPLAIN_TMP"; fi
}
trap cleanup EXIT

# Validar las IPs del equipo antes de tocar nada.
for ip in ${TEAM_IPS[@]+"${TEAM_IPS[@]}"}; do
  valid_ip "$ip" || die "IP de equipo inválida o con rango demasiado amplio (mínimo /16): $ip"
done
if [[ "$ENV_NAME" == "prod" && ${#TEAM_IPS[@]} -gt 0 ]]; then
  warn "estás allowlisteando IPs del equipo en PROD. Por defecto NO se hace: una IP allowlisteada"
  warn "nunca se banea, ni siquiera si es comprometida. Confirma que es lo que quieres."
fi

# ── 1. preflight ───────────────────────────────────────────────────────────────────────────────
preflight() {
  say "[1/9] preflight ($ENV_NAME$( (( DRY )) && echo ', dry-run' ))"
  if (( DRY )); then
    info "[dry-run] verificaría: root, SO, memoria available >= ${MIN_AVAIL_MB} MB, swap, $NGINX_LOG existente y reciente,"
    info "[dry-run] nftables disponible, otra sesión SSH abierta, cobertura de las IPs SSH actuales en la allowlist"
    return 0
  fi
  [[ $EUID -eq 0 ]] || die "correr como root: sudo $0 $*"
  # shellcheck disable=SC1091
  . /etc/os-release
  info "SO: ${PRETTY_NAME:-desconocido} (dev: Ubuntu 26.04 LTS)"

  local avail swap
  avail="$(free -m | awk '/^Mem:/ {print $7}')"
  swap="$(free -m | awk '/^Swap:/ {print $2}')"
  info "memoria available: ${avail} MB (mínimo ${MIN_AVAIL_MB} MB)"
  (( avail >= MIN_AVAIL_MB )) || die "memoria available ${avail} MB < ${MIN_AVAIL_MB} MB: CrowdSec+bouncer+fail2ban usan ~340 MB y esta instancia no tiene swap; riesgo real de OOM. Liberar memoria o subir --min-avail-mb bajo tu responsabilidad."
  (( swap > 0 )) || warn "sin swap (igual que dev): un pico de memoria mata procesos en vez de degradarse"

  [[ -f "$NGINX_LOG" ]] || die "$NGINX_LOG no existe: sin ese log CrowdSec no ve el tráfico HTTP (bind mount del servicio nginx en docker-compose)"
  if [[ -z "$(find "$NGINX_LOG" -mmin -1440 2>/dev/null)" ]]; then
    die "$NGINX_LOG no se escribe hace >24 h: el bind mount de nginx no está funcionando"
  fi
  [[ -z "$(find "$NGINX_LOG" -mmin -10 2>/dev/null)" ]] && warn "$NGINX_LOG sin escrituras en los últimos 10 min (puede ser solo poco tráfico)"

  command -v nft > /dev/null 2>&1 || warn "nft no está instalado todavía (se instala con el paquete nftables)"
  if command -v nft > /dev/null 2>&1; then
    nft list tables > /dev/null 2>&1 || die "nft no puede listar tablas: el kernel no tiene nf_tables disponible"
  fi

  local sessions
  sessions="$(ss -Htn state established '( sport = :22 )' 2>/dev/null | wc -l | tr -d ' ')"
  if (( sessions < 2 )); then
    warn "solo ${sessions} sesión SSH abierta. ABRE UNA SEGUNDA sesión SSH ahora (y déjala abierta) antes de continuar:"
    warn "si algo te corta, la segunda sesión sirve para hacer el rollback del encabezado de este script."
  fi

  if [[ "$ENV_NAME" == "prod" && $ASSUME_YES -eq 0 ]]; then
    read -r -p "Vas a instalar protección de host en PRODUCCIÓN. Escribe 'prod' para continuar: " ans < /dev/tty
    [[ "$ans" == "prod" ]] || die "cancelado"
  fi
}

# ── 2. repo apt + paquetes ─────────────────────────────────────────────────────────────────────
install_packages() {
  say "[2/9] repo apt de CrowdSec y paquetes (crowdsec=$CROWDSEC_VERSION, bouncer=$BOUNCER_VERSION, fail2ban, nftables)"
  if (( DRY )); then
    info "[dry-run] descargaría la clave GPG de packagecloud y verificaría la huella $APT_KEY_FPR"
    info "[dry-run] escribiría /etc/apt/sources.list.d/crowdsec_crowdsec.list (igual que dev)"
    info "[dry-run] bloquearía el arranque de servicios con $POLICY_RC durante apt-get install"
    info "[dry-run] apt-get install crowdsec=$CROWDSEC_VERSION crowdsec-firewall-bouncer-nftables=$BOUNCER_VERSION fail2ban nftables"
    return 0
  fi
  apt-get install -y --no-install-recommends ca-certificates curl gnupg > /dev/null

  if [[ ! -s "$APT_KEYRING" ]]; then
    local key_tmp
    key_tmp="$(mktemp)"
    curl -fsSL https://packagecloud.io/crowdsec/crowdsec/gpgkey < /dev/null | gpg --dearmor > "$key_tmp"
    # Pin de huella: si la clave descargada no es la de dev, algo anda mal (MITM / repo cambiado).
    gpg --show-keys --with-colons "$key_tmp" | grep -q "^fpr:::::::::${APT_KEY_FPR}:" \
      || { rm -f "$key_tmp"; die "la huella de la clave GPG descargada no coincide con $APT_KEY_FPR"; }
    install -D -m 644 -o root -g root "$key_tmp" "$APT_KEYRING"
    rm -f "$key_tmp"
    info "+ $APT_KEYRING (huella verificada)"
  else
    info "= $APT_KEYRING ya existe"
  fi

  put_file /etc/apt/sources.list.d/crowdsec_crowdsec.list 644 <<EOF
deb [signed-by=$APT_KEYRING] https://packagecloud.io/crowdsec/crowdsec/any/ any main
deb-src [signed-by=$APT_KEYRING] https://packagecloud.io/crowdsec/crowdsec/any/ any main
EOF
  apt-get update -qq

  # Los postinst de crowdsec/bouncer/fail2ban arrancan sus servicios: eso pondría el bouncer a
  # aplicar decisiones ANTES de tener la allowlist lista. policy-rc.d 101 = "no arrancar" (se
  # habilitan igual, los arrancamos nosotros en el orden correcto). Solo si nadie más lo definió.
  if [[ ! -e "$POLICY_RC" ]]; then
    printf '#!/bin/sh\nexit 101\n' > "$POLICY_RC"
    chmod 755 "$POLICY_RC"
    POLICY_OWNED=1
  fi
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    -o Dpkg::Options::=--force-confold \
    "crowdsec=${CROWDSEC_VERSION}" "crowdsec-firewall-bouncer-nftables=${BOUNCER_VERSION}" \
    fail2ban nftables
  if (( POLICY_OWNED )); then rm -f "$POLICY_RC"; POLICY_OWNED=0; fi
}

# ── 3. adquisición de logs, colecciones, perfil, simulación ────────────────────────────────────
configure_crowdsec() {
  say "[3/9] adquisición de logs, colecciones, perfil de ban y escenario en simulación"
  if (( DRY )); then
    info "[dry-run] escribiría /etc/crowdsec/acquis.d/nginx-file.yaml ($NGINX_LOG, type nginx)"
    info "[dry-run] escribiría setup.sshd.yaml / setup.linux.yaml solo si el postinst no los generó y los logs existen"
    info "[dry-run] cscli hub update; instalaría colecciones: $COLLECTIONS"
    info "[dry-run] verificaría profiles.yaml (ban 4h) y machine/CAPI registrados"
    if (( OBSERVE )); then
      info "[dry-run] cscli simulation enable --global   (GLOBAL: todo el motor en simulación, modo --observe)"
    else
      info "[dry-run] cscli simulation enable $SIM_SCENARIO"
    fi
    return 0
  fi

  FILE_CHANGED=0
  put_file /etc/crowdsec/acquis.d/nginx-file.yaml 644 <<EOF
filenames:
  - $NGINX_LOG
labels:
  type: nginx
EOF
  [[ "$FILE_CHANGED" -eq 1 ]] && NEEDS_RESTART=1

  # Dev tiene estos dos generados por `cscli setup` (postinst). Si ya existen NO se duplican
  # (adquirir el mismo archivo 2 veces cuenta cada línea doble en los buckets).
  if [[ ! -e /etc/crowdsec/acquis.d/setup.sshd.yaml && -f /var/log/auth.log ]]; then
    put_file /etc/crowdsec/acquis.d/setup.sshd.yaml 644 <<'EOF'
filenames:
  - /var/log/auth.log
  - /var/log/secure
labels:
  type: syslog
source: file
EOF
    NEEDS_RESTART=1
  fi
  if [[ ! -e /etc/crowdsec/acquis.d/setup.linux.yaml && -f /var/log/syslog ]]; then
    put_file /etc/crowdsec/acquis.d/setup.linux.yaml 644 <<'EOF'
filenames:
  - /var/log/messages
  - /var/log/syslog
  - /var/log/kern.log
labels:
  type: syslog
source: file
EOF
    NEEDS_RESTART=1
  fi

  cscli hub update > /dev/null
  local col installed
  installed="$(cscli collections list -o raw | cut -d, -f1,2)"
  for col in $COLLECTIONS; do
    if grep -qx "${col},enabled" <<< "$installed"; then
      info "= colección $col ya instalada"
    else
      cscli collections install "$col" > /dev/null
      info "+ colección $col"
      NEEDS_RESTART=1
    fi
  done

  grep -q 'duration: 4h' /etc/crowdsec/profiles.yaml \
    || warn "profiles.yaml no tiene 'duration: 4h' (dev sí): revisar la duración de los bans"

  # Credenciales del motor local y de la Central API (el postinst normalmente ya las crea).
  grep -q '^login:' /etc/crowdsec/local_api_credentials.yaml 2>/dev/null \
    || cscli machines add --auto > /dev/null
  grep -q '^login:' /etc/crowdsec/online_api_credentials.yaml 2>/dev/null \
    || cscli capi register > /dev/null

  if (( OBSERVE )); then
    # Global PRIMERO (queda `simulation: true` sin exclusiones): así TODO se simula, incluido
    # $SIM_SCENARIO. Habilitar el escenario por separado antes lo dejaría en `exclusions`, que con
    # simulación global significa "este SÍ banea". Salir de observe: ver runbook.
    if cscli simulation status 2>&1 | grep -qi 'global simulation: enabled'; then
      info "= simulación GLOBAL ya activa (modo --observe)"
    else
      # OJO: `cscli simulation enable` SIN --global solo imprime la ayuda y no cambia nada (bug
      # real en la primera instalación de prod, 2026-09-23: quedó `simulation: false` con el
      # bouncer activo). Con --global escribe `simulation: true` en simulation.yaml.
      cscli simulation enable --global > /dev/null
      cscli simulation status 2>&1 | grep -qi 'global simulation: enabled' \
        || die "no se pudo activar la simulación GLOBAL (--observe): abortando ANTES de arrancar el bouncer"
      info "+ simulación GLOBAL activa: ningún escenario genera bans hasta salir de --observe (runbook)"
      NEEDS_RELOAD=1
    fi
  elif cscli simulation status 2>&1 | grep -q "$SIM_SCENARIO"; then
    info "= $SIM_SCENARIO ya está en simulación"
  else
    cscli simulation enable "$SIM_SCENARIO" > /dev/null
    info "+ $SIM_SCENARIO en simulación (ver comentario de SIM_SCENARIO arriba)"
    NEEDS_RELOAD=1
  fi
}
NEEDS_RESTART=0
NEEDS_RELOAD=0

# ── 4. arrancar SOLO el motor y esperar a la API local ─────────────────────────────────────────
start_engine() {
  say "[4/9] arrancar el motor (sin bouncer todavía: nada puede cortar tráfico aún)"
  if (( DRY )); then
    info "[dry-run] crowdsec -t (test de config); systemctl enable --now crowdsec (restart si hubo cambios)"
    info "[dry-run] esperaría a que la API local (127.0.0.1:8080) responda"
    return 0
  fi
  crowdsec -c /etc/crowdsec/config.yaml -t -error
  systemctl enable crowdsec > /dev/null 2>&1
  if systemctl is-active --quiet crowdsec; then
    if (( NEEDS_RESTART )); then systemctl restart crowdsec; elif (( NEEDS_RELOAD )); then systemctl reload crowdsec; fi
  else
    systemctl start crowdsec
  fi
  local i
  for i in $(seq 1 30); do
    if cscli lapi status > /dev/null 2>&1; then info "API local lista"; return 0; fi
    sleep 2
  done
  die "la API local de CrowdSec no respondió en 60 s (journalctl -u crowdsec)"
}

# ── 5. allowlist anti-lockout ──────────────────────────────────────────────────────────────────
ALLOW_VALUES=()
build_allow_values() {
  local own vpc mac ip
  ALLOW_VALUES=("127.0.0.1" "$EIP")
  own="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}' | head -1)"
  [[ -n "$own" ]] && ALLOW_VALUES+=("$own")
  mac="$(imds network/interfaces/macs/ 2>/dev/null | head -1 | tr -d '/')" || mac=""
  if [[ -n "$mac" ]]; then
    vpc="$(imds "network/interfaces/macs/${mac}/vpc-ipv4-cidr-block" 2>/dev/null)" || vpc=""
    [[ -n "$vpc" ]] && valid_ip "$vpc" && ALLOW_VALUES+=("$vpc")
  fi
  for ip in ${TEAM_IPS[@]+"${TEAM_IPS[@]}"}; do ALLOW_VALUES+=("$ip"); done
}

allowlist_has() { # ¿el valor ya está en la allowlist?
  cscli allowlists inspect "$ALLOWLIST_NAME" 2>/dev/null | grep -qF -- "$1"
}

# Agrega el valor y CONFIRMA que quedó: es la protección anti-lockout, no se acepta un fallo mudo.
ensure_allow() { # ensure_allow <valor> <comentario> [expiración]
  local value="$1" comment="$2" expiry="${3:-}"
  if allowlist_has "$value"; then info "= $value ya está en la allowlist"; return 0; fi
  if [[ -n "$expiry" ]]; then
    cscli allowlists add "$ALLOWLIST_NAME" "$value" -e "$expiry" -d "$comment" > /dev/null || true
  else
    cscli allowlists add "$ALLOWLIST_NAME" "$value" -d "$comment" > /dev/null || true
  fi
  allowlist_has "$value" || die "no pude confirmar $value en la allowlist $ALLOWLIST_NAME: no sigo sin la red de seguridad"
  info "+ $value"
}

configure_allowlist() {
  say "[5/9] allowlist '$ALLOWLIST_NAME' (Elastic IP del entorno, IP propia, VPC, IPs del equipo)"
  if (( DRY )); then
    info "[dry-run] cscli allowlists create $ALLOWLIST_NAME"
    info "[dry-run] cscli allowlists add: 127.0.0.1, $EIP (Elastic IP $ENV_NAME), IP privada de la instancia y CIDR de la VPC (IMDSv2)"
    local tip
    for tip in ${TEAM_IPS[@]+"${TEAM_IPS[@]}"}; do info "[dry-run] cscli allowlists add: $tip (IP del equipo, sin expiración)"; done
    (( ALLOW_CURRENT_SSH )) && info "[dry-run] cscli allowlists add: IPs con sesión SSH actual, con expiración 24h"
    info "[dry-run] escribiría /etc/fail2ban/jail.d/atlanticerp-ignoreip.local (ignoreip = mismas IPs) y fail2ban-client -t"
    return 0
  fi

  cscli allowlists list -o raw | cut -d, -f1 | grep -qx "$ALLOWLIST_NAME" \
    || cscli allowlists create "$ALLOWLIST_NAME" -d "AtlanticERP: IPs de infraestructura y equipo que nunca deben banearse" > /dev/null

  build_allow_values
  local v
  for v in "${ALLOW_VALUES[@]}"; do ensure_allow "$v" "install-crowdsec.sh ${ENV_NAME}"; done

  # Cobertura de las sesiones SSH abiertas ahora (típicamente la tuya).
  local sship covered
  for sship in $(current_ssh_ips); do
    covered=0
    is_private_ip "$sship" && covered=1
    for v in "${ALLOW_VALUES[@]}"; do [[ "$v" == "$sship" ]] && covered=1; done
    (( covered )) && continue
    if (( ALLOW_CURRENT_SSH )); then
      ensure_allow "$sship" "sesión SSH durante la instalación (expira)" 24h
      ALLOW_VALUES+=("$sship")
    else
      warn "tu IP $sship (sesión SSH actual) NO está en la allowlist. Si dispara un escenario pierdes SSH."
      warn "usa --allow-current-ssh-ip (expira en 24h) o --team-ip $sship (permanente)."
    fi
  done

  # fail2ban solo cubre SSH, pero también puede cortar a un humano: mismas IPs en ignoreip.
  put_file /etc/fail2ban/jail.d/atlanticerp-ignoreip.local 644 <<EOF
[DEFAULT]
ignoreip = 127.0.0.1/8 ::1 ${ALLOW_VALUES[*]}
EOF
  fail2ban-client -t > /dev/null
}

# ── 6. bouncer nftables (config + api key) ─────────────────────────────────────────────────────
configure_bouncer() {
  say "[6/9] bouncer de firewall ($BOUNCER_NAME) — configuración idéntica a dev"
  if (( DRY )); then
    info "[dry-run] borraría bouncers autoregistrados por el postinst (cs-firewall-bouncer-*)"
    info "[dry-run] cscli bouncers add $BOUNCER_NAME (api key nueva, nunca se imprime)"
    info "[dry-run] escribiría /etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml (modo 600, nftables input+forward, DROP)"
    return 0
  fi
  local conf="/etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml" key="" stale
  key="$(awk -F': *' '/^api_key:/ {print $2}' "$conf" 2>/dev/null | tr -d '[:space:]"')" || key=""

  for stale in $(cscli bouncers list -o raw | cut -d, -f1 | grep '^cs-firewall-bouncer-' || true); do
    cscli bouncers delete "$stale" > /dev/null
    info "- bouncer autoregistrado por el postinst eliminado: $stale"
  done

  if cscli bouncers list -o raw | cut -d, -f1 | grep -qx "$BOUNCER_NAME" \
      && [[ -n "$key" && "$key" != '${API_KEY}' ]]; then
    info "= bouncer $BOUNCER_NAME ya registrado con api key en la config"
  else
    cscli bouncers delete "$BOUNCER_NAME" > /dev/null 2>&1 || true
    key="$(cscli bouncers add "$BOUNCER_NAME" -o raw)"
    info "+ bouncer $BOUNCER_NAME registrado"
  fi

  # Config leída de dev (2026-09-23); solo cambia api_key. nftables_hooks incluye forward: el
  # tráfico publicado por Docker entra por FORWARD, no por INPUT.
  put_file "$conf" 600 <<EOF
mode: nftables
update_frequency: 10s
log_mode: file
log_dir: /var/log/
log_level: info
log_compression: true
log_max_size: 100
log_max_backups: 3
log_max_age: 30
api_url: http://127.0.0.1:8080/
api_key: ${key}
insecure_skip_verify: false
disable_ipv6: false
deny_action: DROP
deny_log: false
supported_decisions_types:
  - ban
blacklists_ipv4: crowdsec-blacklists
blacklists_ipv6: crowdsec6-blacklists
ipset_type: nethash
iptables_chains:
  - INPUT
iptables_add_rule_comments: true
nftables:
  ipv4:
    enabled: true
    set-only: false
    table: crowdsec
    chain: crowdsec-chain
    priority: -10
  ipv6:
    enabled: true
    set-only: false
    table: crowdsec6
    chain: crowdsec6-chain
    priority: -10
nftables_hooks:
  - input
  - forward
pf:
  anchor_name: ""
prometheus:
  enabled: false
  listen_addr: 127.0.0.1
  listen_port: 60601
EOF
}

# ── 7. logrotate del access.log ────────────────────────────────────────────────────────────────
install_logrotate() {
  say "[7/9] logrotate de $NGINX_LOG (copytruncate, 7 días)"
  local src="$REPO_ROOT/infra/logrotate/atlanticerp-nginx-crowdsec"
  if (( DRY )); then
    info "[dry-run] copiaría $src a /etc/logrotate.d/atlanticerp-nginx-crowdsec y validaría con logrotate -d"
    return 0
  fi
  [[ -f "$src" ]] || die "no encuentro $src: copiar también infra/logrotate/atlanticerp-nginx-crowdsec junto al script"
  put_file /etc/logrotate.d/atlanticerp-nginx-crowdsec 644 < "$src"
  logrotate -d /etc/logrotate.d/atlanticerp-nginx-crowdsec > /dev/null 2>&1 || die "logrotate -d falló para atlanticerp-nginx-crowdsec"
}

# ── 8. guarda anti-lockout y arranque del bouncer (siempre último) ─────────────────────────────
start_enforcement() {
  say "[8/9] verificar que ninguna IP nuestra tiene un ban pendiente, y RECIÉN ahí arrancar el bouncer"
  if (( DRY )); then
    info "[dry-run] esperaría 20 s al primer pull de la lista comunitaria (CAPI)"
    info "[dry-run] cscli decisions list --ip <cada IP allowlisteada / sesión SSH actual>: si hay ban, aborta SIN arrancar el bouncer"
    info "[dry-run] systemctl enable --now crowdsec-firewall-bouncer fail2ban"
    return 0
  fi
  info "esperando 20 s a que el motor descargue la lista comunitaria de IPs maliciosas..."
  sleep 20
  local ip banned
  for ip in "${ALLOW_VALUES[@]}" $(current_ssh_ips); do
    [[ "$ip" == */* ]] && continue
    banned="$(cscli decisions list --ip "$ip" -o raw 2>/dev/null | tail -n +2 | wc -l | tr -d ' ')"
    if (( banned > 0 )); then
      die "la IP $ip YA tiene un ban activo en CrowdSec: NO se arranca el bouncer (te dejaría sin acceso). Investigar: cscli decisions list --ip $ip"
    fi
  done
  info "sin bans pendientes sobre nuestras IPs"

  systemctl enable crowdsec-firewall-bouncer > /dev/null 2>&1
  if systemctl is-active --quiet crowdsec-firewall-bouncer; then systemctl restart crowdsec-firewall-bouncer; else systemctl start crowdsec-firewall-bouncer; fi
  systemctl enable --now fail2ban > /dev/null 2>&1
  systemctl restart fail2ban
}

# ── 9. verificación ────────────────────────────────────────────────────────────────────────────
verify() {
  say "[9/9] verificación post-instalación"
  if (( DRY )); then
    info "[dry-run] comprobaría: 3 servicios active; nginx access.log en cscli metrics; bouncer válido con last_pull;"
    info "[dry-run] escenario en simulación; tabla nft 'ip crowdsec'; allowlist; cscli explain informativo con /api/admin/*"
    return 0
  fi
  local fails=0 s i mentions
  check() { # check "descripción" función-o-comando...
    local desc="$1"; shift
    if "$@" > /dev/null 2>&1; then info "OK   $desc"; else info "FAIL $desc"; fails=$((fails + 1)); fi
  }
  metrics_ok()   { cscli metrics show acquisition 2>&1 | grep -q 'nginx-crowdsec/access.log'; }
  sim_ok() {
    if (( OBSERVE )); then cscli simulation status 2>&1 | grep -qi 'global simulation: enabled'
    else cscli simulation status 2>&1 | grep -q "$SIM_SCENARIO"; fi
  }
  allowlist_ok() { cscli allowlists list -o raw | cut -d, -f1 | grep -qx "$ALLOWLIST_NAME"; }
  # Columnas de `cscli bouncers list -o raw`: name,ip,revoked,last_pull,... (revoked="validated" = ok)
  bouncer_ok() {
    local line
    line="$(cscli bouncers list -o raw | grep "^${BOUNCER_NAME}," || true)"
    [[ -n "$line" && "$(cut -d, -f3 <<< "$line")" == "validated" && -n "$(cut -d, -f4 <<< "$line")" ]]
  }

  for s in crowdsec crowdsec-firewall-bouncer fail2ban; do check "servicio $s activo" systemctl is-active --quiet "$s"; done
  check "el motor lee $NGINX_LOG (cscli metrics)" metrics_ok
  for i in 1 2 3 4 5 6; do bouncer_ok && break; sleep 5; done   # el primer pull tarda unos segundos
  check "bouncer $BOUNCER_NAME válido y con last_pull" bouncer_ok
  check "simulación activa ($( (( OBSERVE )) && echo "GLOBAL, --observe" || echo "$SIM_SCENARIO" ))" sim_ok
  check "tabla nft 'ip crowdsec' presente" nft list table ip crowdsec
  check "allowlist $ALLOWLIST_NAME existe" allowlist_ok

  # INFORMATIVO, no una aserción: `cscli explain` corre su propio runtime y NO respeta
  # simulation.yaml (verificado en dev 2026-09-23: el escenario desborda igual con la simulación
  # activa). La garantía de "no banea" es el estado de simulación comprobado arriba.
  EXPLAIN_TMP="$(mktemp)"
  {
    echo '203.0.113.7 - - [23/Sep/2026:19:00:00 +0000] "GET /api/admin/a HTTP/1.1" 403 50 "-" "Mozilla/5.0"'
    echo '203.0.113.7 - - [23/Sep/2026:19:00:01 +0000] "GET /api/admin/b HTTP/1.1" 404 50 "-" "Mozilla/5.0"'
    echo '203.0.113.7 - - [23/Sep/2026:19:00:02 +0000] "GET /api/admin/c HTTP/1.1" 403 50 "-" "Mozilla/5.0"'
  } > "$EXPLAIN_TMP"
  mentions="$(cscli explain --file "$EXPLAIN_TMP" --type nginx 2>&1 | grep -c "$SIM_SCENARIO" || true)"
  info "info: cscli explain con 3 rutas /api/admin/* distintas menciona el escenario ${mentions} veces (esperado: >0; explain ignora la simulación, no prueba el no-ban)"

  if (( fails > 0 )); then
    die "$fails verificación(es) fallaron. Considera el ROLLBACK del encabezado del script."
  fi
  say "Protección de host instalada en $ENV_NAME. Vigilar 24 h: cscli alerts list; cscli decisions list; free -m"
  if (( OBSERVE )); then
    say "MODO --observe: NO se banea a nadie todavía. Revisar cscli alerts list tras 72 h y salir de observe (runbook)."
  fi
}

# ── main ───────────────────────────────────────────────────────────────────────────────────────
preflight
install_packages
configure_crowdsec
start_engine
configure_allowlist
configure_bouncer
install_logrotate
start_enforcement
verify
