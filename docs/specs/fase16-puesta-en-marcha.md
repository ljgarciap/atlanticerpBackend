# Fase 16 — Puesta en marcha y experiencia

**Estado**: APROBADA por Luis (2026-10-30) con las propuestas de §5 (5.1 en la aplicación, 5.3 sí, 5.4 líderes y asistentes conservan el derecho). Origen: grupo 5 de `docs/flujos/candidatos-priorizados.md` (5.1–5.4). No se implementa sin su aprobación.

## 1. Objetivo
Que una empresa nueva llegue de «instalación vacía» a «primer cobro» sin tropezar con dependencias ocultas, y cerrar tres asperezas del flujo de Bodega y Ventas detectadas al recorrer la cadena venta→cobro.

## 2. Hallazgos (medidos sobre `master`)
- **5.1 Dependencias de arranque sin guía.** Sin cuenta bancaria no se registra un cobro; sin los ajustes de archivos no se aprueba un proyecto; el demo no trae existencias (las crea el flujo vía Compras). Hoy el usuario solo se entera al chocar con el error.
- **5.2 Etiqueta de la hoja de picking.** Muestra el disponible *para el pedido* (p. ej. 3) y no el saldo libre de la bodega (p. ej. 7 con 10 en bodega y 3 reservadas). La cifra es correcta pero la etiqueta confunde. Es solo texto/presentación.
- **5.3 Vendedor y pase a pedido.** `POST pipeline/{id}/pass-to-order` exige `ventas_diseno.pass_to_order` (Gerencia lo recibe por rol). El vendedor recibe 403 y no tiene forma de pedir que se pase.
- **5.4 Inicio del picking.** `PickingService::startPicking()` (y `updatePickingSheet`/`completePicking`) autoriza por **rol** (`BodegaRoles::CAN_PICK` = líder, asistente, picker) y no compara con `orders.picker_id`. Confirmado en código: cualquier auxiliar con el rol puede iniciar el picking de un pedido asignado a otro. El mensaje de error dice «Picker asignado», que no es lo que se comprueba.

## 3. Diseño propuesto
- **5.1 Lista de verificación de primera puesta en marcha** (solo lectura, sin esquema nuevo): `GET /api/setup/checklist` calcula pasos a partir de datos existentes —perfil de empresa y región, cuenta bancaria activa, ajustes de archivos, almacén de despacho por defecto, aprobador primario configurado, catálogo de productos, al menos una existencia— y devuelve `{clave, completo, ruta}`. Frontend: tarjeta en el inicio de Gerencia/superadmin con los pasos pendientes y enlace a cada pantalla; desaparece cuando todo está completo. Permiso: el que ya ve el inicio de Gerencia (sin permiso nuevo).
- **5.2 Etiquetas claras**: «Reservado para este pedido» y «Libre en la bodega», es/en. Sin cambio de cálculo.
- **5.3 Solicitud de pase a pedido** (si Luis lo decide): el vendedor registra una solicitud sobre su proyecto aprobado; Gerencia recibe una notificación y la decide. Reutiliza el sistema de notificaciones; no cambia quién ejecuta el pase.
- **5.4 Restringir el picking al picker asignado**: `startPicking`, `updatePickingSheet` y `completePicking` exigen que el actor sea `picker_id` **o** líder/asistente de Bodega (que pueden reasignar). Mensaje 403 que lo diga. Test de regresión con un auxiliar no asignado (403) y el asignado (200).

## 4. Criterios de aceptación
1. En una instalación vacía la lista marca pendientes los pasos faltantes; al completarlos desaparecen; la ruta de cada uno abre la pantalla correcta.
2. La hoja de picking distingue «reservado para este pedido» de «libre en la bodega».
3. (Si se aprueba 5.3) El vendedor puede solicitar el pase; Gerencia ve y decide la solicitud; el vendedor sigue sin poder ejecutar el pase.
4. Un auxiliar no asignado recibe 403 al iniciar, editar o completar el picking de un pedido ajeno; el asignado y los líderes/asistentes siguen pudiendo.
5. Tests de API y de pantalla, PHPStan 0, `tsc` limpio, suites completas en verde.

## 5. Decisiones para Luis
1. **5.1**: ¿lista de verificación en la aplicación (propuesta), o solo documentarla en el manual?
2. **5.3**: ¿se implementa la solicitud del vendedor (más alcance) o se deja como está (solo Gerencia pasa a pedido)? Es decisión de producto.
3. **5.4**: ¿líderes y asistentes de Bodega conservan el derecho a operar el picking de cualquier pedido (propuesta), o solo el picker asignado?
4. **Entrega**: 5.2 y 5.4 en un PR cada uno (pequeños); 5.1 en backend + frontend; 5.3 aparte si se aprueba. 1 PR por ítem, como en la Fase 13.

## 6. Riesgos
5.4 puede bloquear un flujo que hoy «funciona» por accidente (p. ej. asistentes que cubren a un picker): por eso la propuesta mantiene a líderes y asistentes. 5.1 lee tablas de varios módulos; debe ser de solo lectura y barata. 5.2 sin riesgo.

## 7. No-alcance
Rediseñar el flujo de picking, cambiar quién ejecuta el pase a pedido, o crear datos demo adicionales.
