# Documentación interactiva de la API (dev)

`https://dev.atlanticerp.ai/docs/api` (Scramble, interfaz Stoplight Elements) y `https://dev.atlanticerp.ai/docs/api.json`. **Solo dev**: test y prod no la tienen
(Scramble sigue en `require-dev` y esos entornos instalan con `--no-dev`; nginx no define la ruta).

## Cómo se protege
1. **nginx** exige usuario y contraseña (`auth_basic`) en `/docs/api` y `/docs/api.json`. La ruta solo existe si el `.env` del servidor define
   `API_DOCS_BASIC_AUTH_B64`; sin esa variable nginx no la expone (ver `infra/nginx/entrypoint.sh`).
2. nginx marca la petición con `X-Api-Docs-Auth: 1` (`fastcgi_param`, el cliente no puede fijarlo) y el gate `viewApiDocs`
   (`ApiDocsServiceProvider`) lo exige además de `APP_ENV=dev`. Sin nginx delante, Laravel responde 403.
3. El documento se genera en cada despliegue (`scramble:clear` + `scramble:cache`); abrir la página no analiza el código.

## Alta de la contraseña (una vez, en dev; la hace quien administra el servidor)
```bash
# Genera la línea htpasswd y la codifica en base64 (una sola línea, sin saltos)
printf '%s:%s' "<usuario>" "$(openssl passwd -apr1 '<contraseña>')" | base64 | tr -d '\n'
```
Pega el resultado en el `.env` del servidor de dev como `API_DOCS_BASIC_AUTH_B64=<valor>` y guarda usuario y contraseña en AWS Secrets Manager (nunca
en el repo). Después del despliegue, el paso `scramble` recrea el contenedor nginx y toma la variable. Para rotar: cambia el valor y vuelve a desplegar
(o `docker compose -f infra/docker-compose.qa.yml up -d --force-recreate nginx`).

## Verificación tras desplegar
Mientras `API_DOCS_BASIC_AUTH_B64` no esté en el `.env` de dev, `/docs/api` **no existe**: nginx no define la ruta y cualquier URL desconocida cae en el frontend
de React, que responde `200` con `<title>Atlantic</title>`. Eso es lo esperado, no una fuga; un `200` solo significa documentación si el cuerpo trae la
interfaz de Stoplight.

Con la variable definida y nginx recreado:
- Sin credenciales → `401`: `curl -s -o /dev/null -w '%{http_code}' https://dev.atlanticerp.ai/docs/api`
- Con credenciales → `200` y el cuerpo contiene `elements-api` / Stoplight: `curl -s -u usuario:contraseña https://dev.atlanticerp.ai/docs/api | grep -c -i stoplight`
- `https://test.atlanticerp.ai/docs/api` y `https://atlanticerp.ai/docs/api` → siguen devolviendo el frontend de React (`<title>Atlantic</title>`), nunca la documentación.

## Limitaciones conocidas
- La interfaz carga Stoplight Elements desde `unpkg.com`. La CSP de nginx está en modo solo informe; si pasa a bloqueante hay que permitir ese origen
  para `/docs/api`.
- «Try it» envía las peticiones con las cookies del navegador, no con el JWT: para probar endpoints autenticados pega el token del login en el campo
  de autorización de la interfaz.
- `servers` apunta a dev y test; prod no figura a propósito.
