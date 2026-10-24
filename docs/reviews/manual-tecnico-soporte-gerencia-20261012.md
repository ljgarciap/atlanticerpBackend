# Senior Review — Manuales técnicos de Soporte y Gerencia

2026-10-12 · Documentos: `docs/manuales-tecnicos/soporte.md` y `docs/manuales-tecnicos/gerencia.md` · Misma plantilla y método que Compras y Bodega.

## Cobertura
- Soporte: 7 de 7 elementos nombrados (controlador, servicio, ruta, 3 piezas de frontend, 1 prueba).
- Gerencia: 27 de 27 (3 controladores, Form Request, modelo, 2 servicios, 2 tablas, 11 rutas, 5 piezas de frontend, 2 pruebas).

## Muestra de afirmaciones contrastadas
Soporte: formato de `external_user_id`, endpoint y tiempo de espera del servicio, nombre del circuito, límite de 20 por minuto, los cuatro casos de prueba, los códigos de respuesta, el script y atributos del widget en `SoportePage` y el módulo hermano `Lumo`.
Gerencia: filtros y valores por defecto (servidor frente a pantalla), fuentes que reutiliza cada bloque, matriz de `AprobacionGate`, delegación a cuatro controladores, mapeo de `razon` a `motivo_rechazo` o `rejection_reason`, límite de 20 ítems por sección de la bandeja, validaciones, middleware `role:superadmin`, lista de vendedores y de clientes (máximo 50).

## Correcciones hechas en el repaso
| # | Afirmación inicial | Corrección |
|---|---|---|
| 1 | Soporte: "sin la clave, el sistema de tickets rechaza el pedido" | Era una inferencia; se reescribió como "el pedido falla y se muestra 'no disponible'". |
| 2 | Soporte: "la clave no está en Secrets Manager" | No se verificó; se retiró y se dejó "alta y rotación manuales, sin procedimiento documentado en este repositorio". |

## Hallazgos de contenido que los manuales dejan registrados (no se corrigieron)
- **Gerencia, probable defecto de exportación a Excel** (verificado por lectura, no ejecutado): el frontend envía `format=xlsx` y el backend solo acepta `pdf,excel`; además el frontend busca `no_data` y el backend responde `empty`. Requiere confirmación en dev y un ticket.
- Gerencia usa una definición de "despachos urgentes" distinta de la de Bodega.
- Soporte: la URL del widget está escrita en el frontend y no lee `TICKETS_BASE_URL`.
- Comentarios desactualizados en `GerenciaHomeService`.

## Límites
- Verificación por muestreo. No se ejecutó ninguna prueba ni se llamó a ningún endpoint real.
- No se leyó cada fórmula de `GerenciaHomeService` (unas 800 líneas): se documentó la fuente de cada bloque, no cada operación.
- El frontend se leyó de `origin/dev` (95cc675).

## Veredicto
Aprobados.
