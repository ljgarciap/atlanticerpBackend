# Senior Review — Manual técnico de CRM

2026-10-12 · Documento: `docs/manuales-tecnicos/crm.md` · Misma plantilla y método.

## Cobertura
126 de 126 elementos nombrados: 13 controladores, 7 Form Requests, 8 modelos, 7 servicios, 6 eventos, 4 listeners, 9 tablas, el motor de reglas (3 clases), 32 rutas, 23 piezas de frontend y 13 pruebas.

## Hallazgo principal del repaso
Este módulo **no es el CRM que el usuario ve** en el menú. La épica CRM (SCRUM-332) se construyó sobre Ventas & Diseño y sus pantallas llaman a `ventasDisenoApi`. El backend de `App\Modules\CRM` sigue en uso por IA, el motor de reglas de notificación, los umbrales (que también usa el Catálogo) y el enlace público, pero la mayoría de sus rutas no tienen consumidor en el frontend de `origin/dev`. Lo verifiqué buscando los importadores de cada cliente de API y de cada componente. El manual abre con esa advertencia (sección 0).

## Muestra contrastada con el código
Etapas, tipos y categorías (Form Requests); permisos y el doble gate de borrado de proyectos; umbrales y sus valores por defecto; versionado de documentos; zonas y TTL de URL prefirmadas; ciclo de vida del enlace compartido (hash, vencimiento, revocación por id, límite de 30/min); lista permitida del motor de reglas y la resolución de audiencia; nombres de los eventos y quién los emite; consumidores del módulo en IA y en Ventas & Diseño.

## Correcciones hechas en el repaso
| # | Afirmación inicial | Corrección |
|---|---|---|
| 1 | `DocumentController` emite los eventos de documentos | Los emite `DocumentService` (`upload` y `delete`). |
| 2 | `projectsApi` solo lo importan componentes de la interfaz original | También lo importa `CrmSettingsPanel` (ajustes). |
| 3 | "No hay restricción en la base para `etapa`/`tipo`" | No se revisó; se reescribió como "no se revisó". |
| 4 | Un enlace a `docs/specs/auditoria-valores-parametricos-superadmin.md` | Ese archivo no existe en `docs/specs/`; se retiró el enlace. |
| 5 | Los textos usan un namespace `crm` | No verificado; se retiró la afirmación. |

## Hallazgos de contenido que el manual deja registrados (no se corrigieron)
- Etiquetas `urgency` y `freshness` con valores fijos (3, 7 y 21 días) que ignoran `crm_settings`.
- Regla de acceso a proyectos copiada en cada controlador.
- Cuatro de siete umbrales pertenecen al Catálogo de Ventas & Diseño.
- `date_proximity` reservada pero rechazada.
- Backend sin interfaz que conviene retirar, reactivar o migrar.

## Límites
- Verificación por muestreo; no se leyeron `DocumentController` (357 líneas), `ProjectController` (303) ni `ReportsService` (313) línea por línea.
- "Sin consumidor" significa sin consumidor en el repositorio del frontend y de los otros módulos del backend; no se revisaron clientes externos.
- No se ejecutó ninguna prueba.

## Veredicto
Aprobado.
