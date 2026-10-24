# Senior Review — Manual técnico de Factura Electrónica

2026-10-12 · Documento: `docs/manuales-tecnicos/factura-electronica.md` · Misma plantilla y método. Módulo sensible (DGI, PAC): la revisión de contenido fue más estricta que en los otros.

## Cobertura
105 de 105 elementos nombrados: 11 servicios, 5 excepciones, 1 listener, 4 tablas, 14 piezas de la capa (adaptadores, DTO, mapeadores, contrato), 5 controladores, 7 modelos y solicitudes, 3 comandos y seeders, 15 rutas, 13 piezas de frontend y 27 pruebas.

## Muestra contrastada con el código
Estados de `fe_estado` y la interpretación de las respuestas de MSL (200, 202, 422 y el resto); guarda de producción (`APP_ENV`); endpoints y cabecera de MSL; tiempos y límites (15 s, 90 s, 20 intentos, 6 y 20 por minuto); códigos de bloqueo de las acciones manuales; reglas de la guarda de RUC; comportamiento de `primary_approver_only` (falla cerrada si no hay aprobador); el ítem 3.5 % sin código; el liberado atómico del reemplazo de una corrección; el origen de cada transmisión.

## Correcciones hechas en el repaso
| # | Afirmación inicial | Corrección |
|---|---|---|
| 1 | "Esquema `{tenant}_admin_contab`" como propio de FE | FE no tiene esquema propio: es un subconjunto del de Admin & Contabilidad (lo dice el encabezado del ERD). |
| 2 | Reglas de `CorrectInvoiceReceptorRequest` descritas en general | Reescritas con las reglas reales: `business_name`, `tax_id`, `tax_id_dv`. El detalle de `UpdateSubClientTaxDataRequest` no se revisó y así se declara. |
| 3 | "`ClientDetailModal` usa el validador de RUC" | Lo usan `CreateClientModal` y `EditSubClientTaxDataModal`. |
| 4 | `ClientesFiscalPage` sin ruta | Ruta y gate reales: `/admin-contab/datos-fiscales-clientes`, `admin_contab.view` más cuatro roles. |

## Hallazgos de contenido que el manual deja registrados (no se corrigieron)
- La base de conocimiento fiscal sigue diciendo que la retención 3.5 % mapea al código `01`; el código ya la rechaza. El código manda.
- Dos campos distintos `primary_approver_user_id` (Compras y Fiscal) pueden desincronizarse.
- Digifact directo no está cableado y su esquema no se verificó campo a campo.
- Catálogo de formas de pago a reconfirmar antes de producción.
- Sin pruebas de frontend de varios componentes de FE.

## Límites
- Verificación por muestreo; `FiscalCertificationService` tiene 831 líneas y no se leyó entero (se leyeron la certificación, el envío, los bloqueos, `persistirResultado` y la liberación de reemplazos).
- No se leyeron `NucJsonMapper` ni `MslJsonMapper` campo por campo.
- No se ejecutó ninguna prueba ni se llamó a MSL.
- No se verificó el estado actual de producción ni el valor real de `pac_ambiente` en ningún entorno.
- El frontend se leyó de `origin/dev` (95cc675).

## Veredicto
Aprobado, con la advertencia de que cualquier cambio en este módulo requiere al agente `fe-panama` y, si toca credenciales o datos del receptor, a Cybersecurity.
