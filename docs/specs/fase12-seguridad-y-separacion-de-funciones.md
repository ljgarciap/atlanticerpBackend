# Spec — Fase 12: seguridad y separación de funciones

**Estado**: decisiones de Luis registradas (§5, 2026-10-29); implementación por sub-fases, un PR por entrega.
**Origen**: Grupo 1 de `docs/flujos/candidatos-priorizados.md` (Fase 11).

## 1. Hechos verificados (ejecutados el 2026-10-29)
1. **Permisos adicionales** (`PUT users/{id}`, campo `extra_permissions`): solo superadmin los asigna. Si los envía otra cuenta con permiso de editar usuarios, la respuesta es **200 y el campo queda como estaba** (`UserService::create/update` lo reemplaza por `[]`/lo ignora cuando `$actorHasSuperadmin` es falso). La protección es correcta (evita la escalada de privilegios) pero la respuesta no avisa.
2. **Sustitución de producto** (`compras/replacement-requests/{id}/approve|reject`): decide Gerencia/superadmin, o **el responsable del proyecto** (`isProjectResponsible`) mientras la solicitud no esté `pendiente_gerencia`. El vendedor que la reportó es normalmente ese responsable y pudo aprobarla él mismo (margen sobre el mínimo). Rechazar no pide motivo.
3. **Tickets de Servicios** (`PATCH tickets/{id}/estado`): `Rule::in(ESTADOS)` y una única compuerta (cotización/informe) al pasar a `resolved`/`closed`. Un ticket pasó de `reported` a `closed` sin agendar ni técnico, volvió de `closed` a `reported`, y uno `cancelled` se movió a `on_site`. La cancelación con motivo ya no se salta (backend #41).
4. **Un solo aprobador** (`primary_approver_user_id`): decide notas de crédito, anulaciones y devoluciones de dinero, cambios de monto y aprobación de órdenes de compra, ajustes/reubicaciones/conteos de inventario, incobrables, caja chica, arqueos y tramos de comisión. Si no está, ninguna de esas decisiones es posible; otra cuenta de Gerencia recibe 403 aunque tenga el permiso de ruta.
5. **Clave inicial = correo** (decisión del 2026-10-05, `WelcomeMail`), con cambio obligatorio en el primer ingreso y sin caducidad; el reinicio por superadmin la deja igual y no avisa.

## 2. Diseño propuesto (por sub-fase)
**12a — Correcciones rápidas** (backend, con tests de regresión y de contrato en el frontend)
- *Permisos adicionales*: si quien no es superadmin envía `extra_permissions` **con un valor distinto del guardado**, responder 403 con un mensaje claro; si coincide, aceptar sin cambios (la pantalla suele reenviar el cuerpo completo). La pantalla oculta el selector a quien no puede usarlo (ya lo hace para algunos roles; se revisa).
- *Transiciones de ticket*: matriz de destinos permitidos por estado actual (`reported → scheduled|cancelled`, `scheduled → on_site|reported|cancelled`, `on_site → resolved|scheduled`, `resolved → closed|on_site`, `closed`/`cancelled` finales salvo reapertura explícita con permiso y motivo). `on_site` exige técnico asignado y agenda; el mensaje dice qué falta. Los tickets existentes no se tocan.
- *Sustitución*: según la decisión §5-3.

**12b — Aprobador suplente**
- Ajuste `primary_approver_user_ids` (lista ordenada, el primero es el titular) en lugar de un solo id; `AprobacionGate`/`primary_approver_only` aceptan a cualquiera de la lista; cada decisión registra **quién** decidió. Pantalla en Configuración fiscal / Ajustes para administrarla (solo superadmin). Migración que conserva el aprobador actual como titular.
- Los mensajes de 403 distinguen «no eres el aprobador» de «falta un permiso».

**12c — Activación de cuenta** (solo si §5-5 lo aprueba)
- Alta y reinicio generan un enlace de un solo uso con caducidad (48 h configurable) que lleva a elegir la clave; sin clave inicial utilizable. En local el correo se registra en el log. Requiere un proveedor de correo real para producción (hoy `MAIL_MAILER=log`).

## 3. Fuera de alcance
MFA, recuperación de clave por correo (ya existe), roles/módulos, las otras fases de la lista.

## 4. Criterios de aceptación
1. Cada cambio tiene test de regresión que **falla sin el arreglo** y suite completa verde en el CI.
2. `npm run e2e:flujos` se actualiza y sigue verde; el flujo transversal afirma el nuevo comportamiento (403 en vez de descarte silencioso).
3. Ninguna migración destructiva; los tickets y aprobadores existentes siguen funcionando.
4. Textos de commits, PR y datos de prueba sin nombres reales.

## 5. Decisiones de Luis (2026-10-29)
1. Permisos adicionales de quien no es superadmin: **403 si el valor cambia**; el mismo valor se acepta; la pantalla oculta el selector a quien no puede usarlo. ✅
2. Tickets de Servicios: **matriz estricta** (destinos válidos por estado; `on_site` exige técnico y agenda; `closed`/`cancelled` finales salvo reapertura con permiso y motivo; los tickets existentes no se tocan). ✅
3. Sustituciones: **decide otra persona** cuando el responsable del proyecto es quien la reportó (Gerencia o Compras); rechazar pide motivo. ✅
4. Alcance: **12a, 12b y 12c** (aprobador suplente en lista; enlace de activación de cuenta, con el correo registrado en el log mientras no haya proveedor de correo). ✅
5. Entrega: 12a primero (un PR por ítem), después 12b y 12c; cada una con su informe de revisión y aprobación de Luis para mezclar.
