# Candidatos a cambio o mejora — lista priorizada

Cierre de la Fase 11. Consolida los candidatos de los siete catálogos de `docs/flujos/` (58 en total; aquí se excluyen los «sin ejercitar» y los ya corregidos: ids no numéricos, subida de archivos, cancelación de tickets sin motivo, reclamos con líneas ajenas, devolución que recibía de más, listado de familias, nombres en `docs/`). **Ninguno está implementado: cada fase propuesta necesita tu «adelante».**

**Impacto**: A = riesgo de seguridad, dinero o integridad; M = confusión o trabajo manual; B = cosmético o deuda. **Esfuerzo**: S = horas, M = 1–2 días, L = más.

## Grupo 1 — Seguridad y separación de funciones (propuesta: Fase 12)
| # | Candidato | Fuente | Impacto | Esfuerzo | Propuesta |
|---|---|---|---|---|---|
| 1.1 | Los permisos adicionales asignados por quien no es superadmin responden 200 y **no se guardan** | transversales 2 | A | S | rechazar con 403/422 y ocultar el campo en la pantalla |
| 1.2 | La clave inicial (y la de un reinicio) es el correo del usuario | transversales 1, 6 | A | M | enlace de activación de un solo uso con caducidad; aviso al reiniciar |
| 1.3 | El solicitante puede aprobar su propia sustitución de producto | compras 4 | A | S | impedir que quien solicita decida |
| 1.4 | **Un solo aprobador** decide notas, anulaciones, ajustes, conteos, cierres y caja chica; si falta, todo se detiene; Gerencia tiene el permiso y aun así recibe 403 | notas 6, cierres 8, bodega 1 | A | M | aprobador suplente configurable y mensajes que expliquen por qué |
| 1.5 | Las transiciones de estado de un ticket son libres: de `reported` a `closed` sin agendar ni técnico; un cancelado se reabre | servicios 1, 2, 5 | A | M | matriz de transiciones permitidas y compuertas por tipo |
| 1.6 | El motivo de «Ver como usuario» es texto libre y lo autoriza una sola persona | transversales 5 | B | S | lista de motivos y registro más visible |

## Grupo 2 — Integridad contable y saldos (propuesta: Fase 13)
| # | Candidato | Fuente | Impacto | Esfuerzo | Propuesta |
|---|---|---|---|---|---|
| 2.1 | El cambio de monto de una orden de compra cambia el total **sin tocar las líneas** | compras 2 | A | M | repartir el cambio en las líneas o bloquearlo sin detalle |
| 2.2 | El saldo a favor se muestra con **cifras distintas** en tres lugares (`open-invoices`, `saldo-favor`, vista previa de compensación) | cierres 2, notas 2–3, cadena 8 | A | M | una sola fuente de verdad y ids de origen en el listado |
| 2.3 | Reversar una compensación no pide aprobación y anular un cobro sí; ambos reabren la deuda | cierres 1 | A | S | unificar el criterio (decisión de negocio) |
| 2.4 | Tras anular un cobro la factura reaparece bruta aunque esté acreditada por notas | notas 4 | M | M | proponer la compensación automática |
| 2.5 | El contador de folios no se reconcilia con las facturas existentes (500 al chocar) | cadena 4 | M | S | tomar el máximo existente al asignar |
| 2.6 | Facturar «completo» cobra artículos no entregados sin ningún aviso | cadena 3 | M | S | advertencia visible en la vista previa |
| 2.7 | Los pagos a proveedor no tienen tope contra lo recibido | compras 7 | M | S | decisión de negocio: avisar o bloquear |
| 2.8 | Una factura incobrable conserva el estado `facturada` y se puede volver a marcar | cierres 3 | M | S | estado propio y ocultar la acción |
| 2.9 | La cantidad recibida de una devolución puede superar la solicitada (hasta lo entregado) | bodega 3 | M | S | decisión de negocio |
| 2.10 | Un arqueo cerrado no se puede corregir | cierres 7 | M | M | reapertura con aprobación |
| 2.11 | La anulación completa pide un `monto` que ignora | notas 1 | B | S | quitar el campo |

## Grupo 3 — Reglas parametrizables y catálogos por empresa (propuesta: Fase 14)
La regla del workspace exige que los umbrales y vocabularios de negocio no vivan en el código.
| # | Candidato | Fuente | Impacto | Esfuerzo | Propuesta |
|---|---|---|---|---|---|
| 3.1 | **La regla de aprobación de una orden de compra está fija en el código** (envío aéreo pagado por la empresa) | compras 1 | A | M | tabla de reglas: tipo de envío, monto, quién paga |
| 3.2 | Formas de pago fijas, con un valor regional (Yappy) | cierres 5 | M | M | catálogo por empresa y país |
| 3.3 | Listas fijas: roles de contacto, motivos de ajuste y de devolución, especialidades de técnico | cadena 2, bodega 4, servicios 6 | M | M | pasarlas al mecanismo de catálogos de la Fase 7 |
| 3.4 | El umbral de aprobación se comparte entre notas de crédito y compras bajo un nombre ambiguo | notas 5 | B | S | separar o renombrar |
| 3.5 | Los tramos de comisión se muestran con límites distintos de los que se aplican | cierres 6 | B | S | corregir la presentación |

## Grupo 4 — Deuda heredada (propuesta: Fase 15)
| # | Candidato | Fuente | Impacto | Esfuerzo | Propuesta |
|---|---|---|---|---|---|
| 4.1 | `cobros_v2_activo` nace apagado y los abonos heredados siguen en el código | cadena 5, cierres 4 | M | M | dejar un solo concepto de cobros y retirar bandera y rutas |
| 4.2 | La cotización tiene dos estados (`status`, `document_status`) | cadena 1 | M | M | unificar |
| 4.3 | Nombres de estado y de acción que confunden: `por_aprobar` tras aprobar, «aprobada» no genera y «rechazada» sí, conteo `aprobada` tras aplicarse | compras 5, 6, bodega 2 | B | S | renombrar con migración |
| 4.4 | `internal_technician_id` recibe un id de usuario | servicios 3 | B | S | renombrar el campo |
| 4.5 | El subtipo «installation» es a la vez tipo y subtipo | servicios 4 | B | M | normalizar el catálogo |
| 4.6 | Los niveles de seguridad admiten nombres repetidos e ignoran el `level` pedido; usuarios y niveles no se borran | transversales 3, 4 | B | S | unicidad y política de archivado |
| 4.7 | El manual técnico de Ventas & Diseño está desactualizado y hay un manual de un módulo eliminado | cadena 10 | B | S | actualizar o archivar |

## Grupo 5 — Puesta en marcha y experiencia (propuesta: Fase 16)
| # | Candidato | Fuente | Impacto | Esfuerzo | Propuesta |
|---|---|---|---|---|---|
| 5.1 | Faltan guías de arranque: sin cuenta bancaria no se cobra, sin almacenamiento no se aprueba un proyecto, el demo no trae existencias | cadena 6 | M | M | lista de verificación de primera puesta en marcha en la aplicación |
| 5.2 | La hoja de picking muestra «disponible» para el pedido, no el saldo libre | cadena 9 | B | S | etiquetas claras |
| 5.3 | El vendedor no puede pedir que se pase un proyecto a pedido | cadena 12 | B | S | decisión de producto |
| 5.4 | El picking de un pedido lo pudo iniciar un auxiliar no asignado | cadena 7 | M | S | confirmar con un caso controlado y, si procede, restringir |

## Orden sugerido
1. **Fase 12 (seguridad)**: 1.1, 1.3, 1.5 son rápidas y de alto impacto; 1.2 y 1.4 cambian procesos.
2. **Fase 13 (integridad contable)**: 2.1 y 2.2 primero, porque afectan cifras de dinero.
3. **Fase 14 (parametrización)**: 3.1 es la única que incumple una regla dura del workspace.
4. **Fases 15 y 16**: cuando la operación local esté estable y se retome el despliegue.

Pendiente de recorrer (no es candidato sino cobertura): ubicaciones, rutas del día, Zona Libre, logística, reposición, técnicos externos, herramientas, insumos, MFA y recuperación de clave.
