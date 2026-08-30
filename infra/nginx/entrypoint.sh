#!/bin/sh
# nginx entrypoint — selecciona config SSL o HTTP según si existen los certs.
# Montado en el contenedor nginx; docker-compose no interpola variables aquí.
set -e

CERT="/etc/letsencrypt/live/${NGINX_DOMAIN}/fullchain.pem"

if [ -n "${NGINX_DOMAIN}" ] && [ -f "${CERT}" ]; then
    echo "SSL OK — activando HTTPS para ${NGINX_DOMAIN}"
    envsubst '${NGINX_DOMAIN}' < /etc/nginx/conf.d/ssl.conf.template \
        > /etc/nginx/conf.d/default.conf
else
    echo "Sin certs SSL — usando HTTP (ejecutar Paso 15 para activar HTTPS)"
    cp /etc/nginx/conf.d/http.conf.template /etc/nginx/conf.d/default.conf
fi

exec nginx -g 'daemon off;'
