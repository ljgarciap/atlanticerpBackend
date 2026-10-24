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

# Documentación interactiva de la API (Scramble), SOLO dev. Se activa únicamente si el .env del servidor define
# API_DOCS_BASIC_AUTH_B64 con la línea htpasswd ("usuario:hash", de `openssl passwd -apr1`) codificada en base64 (el
# hash lleva "$", que Docker Compose interpolaría al leer el .env; en base64 llega intacto); sin ella el
# snippet queda vacío y el comportamiento es el de siempre (test y prod nunca la definen). La contraseña real vive
# en Secrets Manager y en el .env del servidor, jamás en el repositorio.
DOCS_SNIPPET=/etc/nginx/snippets/api-docs.conf
: > "${DOCS_SNIPPET}"
if [ -n "${API_DOCS_BASIC_AUTH_B64}" ]; then
    echo "${API_DOCS_BASIC_AUTH_B64}" | base64 -d > /etc/nginx/api-docs.htpasswd
    # Los workers (www-data) abren este archivo en cada petición: root:www-data 640 (sin lectura para otros usuarios).
    chown root:www-data /etc/nginx/api-docs.htpasswd
    chmod 640 /etc/nginx/api-docs.htpasswd
    cat > "${DOCS_SNIPPET}" <<'EOF_DOCS'
    location ~ ^/docs/api(\.json)?$ {
        auth_basic "AtlanticERP API docs";
        auth_basic_user_file /etc/nginx/api-docs.htpasswd;
        include /etc/nginx/snippets/security-headers.conf;
        add_header Cache-Control "no-store" always;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_pass $laravel_upstream;
        fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;
        fastcgi_param SCRIPT_NAME     /index.php;
        fastcgi_param REQUEST_URI     $request_uri;
        fastcgi_param HTTP_X_API_DOCS_AUTH 1;
        fastcgi_connect_timeout 5s;
        fastcgi_read_timeout 120s;
    }
EOF_DOCS
    echo "API docs: activada (autenticación básica)"
fi

exec nginx -g 'daemon off;'
