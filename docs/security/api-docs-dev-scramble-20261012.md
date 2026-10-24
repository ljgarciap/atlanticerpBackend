# Revisión de seguridad — documentación interactiva de la API en dev (Scramble)

2026-10-12 · Rol: Cybersecurity (revisión de diff, sin pruebas dinámicas contra un servidor) · Alcance: commit `46349ebd` y su corrección.

## Superficie nueva
`GET https://dev.atlanticerp.ai/docs/api` y `/docs/api.json`: lista completa de rutas, parámetros y esquemas de la API. Solo dev. Test y prod no cambian.

## Modelo de amenaza
| Amenaza | Control | Estado |
|---|---|---|
| Acceso anónimo a la lista de rutas | `auth_basic` en nginx; la ruta no existe si el `.env` no define `API_DOCS_BASIC_AUTH_B64` | OK |
| Falsificar la marca de nginx desde fuera | `fastcgi_param HTTP_X_API_DOCS_AUTH 1` solo dentro de la ubicación protegida; el gate exige además `APP_ENV=dev`. En test/prod el paquete no está instalado, así que el gate ni existe | OK |
| Llegar a Laravel por otra ruta sin pasar por la autenticación | Solo la ubicación `^/docs/api(\.json)?$` envía `/docs/api*` a PHP; cualquier otra ruta cae en la SPA | OK |
| Fuerza bruta de la contraseña | CrowdSec ya vigila 401 repetidos en dev (`http-generic-401-bf`). Sin límite propio de nginx | Aceptado, riesgo bajo (solo dev) |
| Contraseña en el repo o en los logs | Solo viaja en el `.env` del servidor (base64) y en Secrets Manager; el repo contiene el procedimiento, no el valor | OK |
| Contraseña en claro por la red | Dev sirve HTTPS. La plantilla HTTP incluye la ruta solo por uniformidad; sin certificados no hay dominio público | Aceptado |
| Más código en dev por instalar dependencias de desarrollo | Solo dev; sin credenciales nuevas. Aumenta el uso de disco (dev ya vigila el 80 %) | Aceptado |
| Fuga de información en la propia documentación | Describe la API que ya conoce el frontend; no incluye datos. `servers` omite producción a propósito | OK |

## Hallazgos
- **C1 (alto, corregido):** el archivo de contraseñas quedaba `root:root 640`; los workers de nginx corren como `www-data` y lo abren en cada petición, así que toda petición habría dado 500. Corregido con `chown root:www-data` y `chmod 640`. Sigue sin lectura para otros usuarios.
- **C2 (bajo, pendiente):** la interfaz carga Stoplight Elements desde `unpkg.com`. La CSP está en modo solo informe; si pasa a bloqueante hay que permitir ese origen para esta ruta (anotado en el runbook).

## Pendiente de verificar en dev tras el despliegue
`/docs/api` sin credenciales → 401; con credenciales → 200; el mismo path en test y prod → no sirve documentación.
