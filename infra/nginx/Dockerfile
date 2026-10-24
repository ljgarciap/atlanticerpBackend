# Epic 11 — Seguridad Agéntica (ADR-005). ModSecurity + OWASP CRS no tienen paquete en Alpine
# (confirmado: ni en main/community/testing) ni en la imagen Docker oficial de OWASP CRS
# (línea 4.x sin build amd64/arm64 — ver ADR-005). Debian bookworm sí los empaqueta, pero el
# módulo de ModSecurity solo carga contra el build de Nginx DE DEBIAN (ABI propio) — no contra
# el build de nginx.org que usa nginx:alpine. Por eso la base cambia de nginx:alpine a Debian,
# instalando nginx desde el mismo repo que el módulo para que el ABI coincida siempre.
FROM debian:bookworm-slim

RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        nginx \
        libnginx-mod-http-modsecurity \
        modsecurity-crs \
        gettext-base \
    && rm -rf /var/lib/apt/lists/*

# El paquete nginx de Debian trae su propio sitio default (server_name "_" en el puerto 80,
# vía sites-enabled/) que compite con el nuestro (montado en conf.d/) — sin esto, Debian
# ganaba silenciosamente y nuestra app nunca respondía (confirmado al probar: 403/404 en vez
# de la app real, con un warning de "conflicting server name" en el log).
RUN rm -f /etc/nginx/sites-enabled/default

# El nginx.conf de Debian no trae un log_format "main" con nombre (a diferencia del que
# traía nginx:alpine) — nuestros server blocks lo referencian por nombre para el log que lee
# CrowdSec. Se define acá, una sola vez, en vez de en cada template montado.
COPY 00-log-format.conf /etc/nginx/conf.d/00-log-format.conf

# main.conf: lo que referencian los server blocks vía `modsecurity_rules_file`. Activa el
# motor (modsecurity.conf del paquete — DetectionOnly por defecto) + las reglas CRS (setup +
# todas las reglas empaquetadas).
RUN { \
      echo 'Include /etc/nginx/modsecurity.conf'; \
      echo 'Include /etc/modsecurity/crs/crs-setup.conf'; \
      echo 'Include /usr/share/modsecurity-crs/rules/*.conf'; \
    } > /etc/nginx/modsec-main.conf

# SCRUM-1032 (A-07): cabeceras de seguridad (fragmentos incluidos por los server blocks) y server_tokens off.
COPY security-headers.conf security-headers-https.conf /etc/nginx/snippets/
COPY 01-hardening.conf /etc/nginx/conf.d/01-hardening.conf
# CAP-02: rechazo rápido ante saturación (zonas) y los fragmentos con los límites numéricos
COPY 02-load-shedding.conf /etc/nginx/conf.d/02-load-shedding.conf
COPY load-shedding.conf load-shedding-login.conf /etc/nginx/snippets/

EXPOSE 80 443

CMD ["nginx", "-g", "daemon off;"]
