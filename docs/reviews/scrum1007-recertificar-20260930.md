# Senior Review — SCRUM-1007 "Reintentar certificación" / "Actualizar estado" (FE)

**Fecha:** 2026-09-30 · **Revisor:** Senior Reviewer · **Alcance:** trabajo sin commitear en `atlanticerp-backend` y `atlanticerp-frontend` (solo los paths del ticket).
**Modo:** solo lectura. No se corrieron tests ni PHPStan (había una suite completa corriendo sobre la BD compartida). Se toma como evidencia lo que reportó el Arquitecto: PHPStan L8 limpio, FeReintentoTest 19/19, vitest 188 archivos / 2106 tests, tsc limpio.
**Spec:** `docs/specs/scrum-1007-recertificar-documentos-fe.md` (D1 Aprobador/superadmin, D2 incluir "Actualizar estado", D3 solo throttle).

## Decision

**CHANGES NEEDED**: 1 blocker, acotado y barato de corregir. El diseño está bien resuelto: el reintento entra por `certificar()` y no por `certificarFactura()`, así que no pisa a `no_aplica`; hay lock con relectura, el permiso es deny-by-default y las transmisiones quedan registradas. El problema está en cómo se trata el `9022` dentro de "Actualizar estado": ese camino escribe un estado fiscal falso. Hay además varias sugerencias. Dos de ellas (la llave del throttle y el mensaje cuando falla el transporte) conviene resolverlas en la misma pasada.

---

## Blockers 🔴

### B1. Un `9022` que llega por "Actualizar estado" deja como `rechazado` un documento que MSL dice tener ya enviado
`app/Modules/AdminCont/FacturacionElectronica/Services/FiscalCertificationService.php:192-200` (el 404 dispara `reenviar()`, que termina en `persistirResultado()`), junto con `:285` y `:306`.

Cuando el POST de reenvío devuelve `9022` ("La referencia INTERNA ya ha sido enviada a certificar"), `certificar()` lo pasa a `persistirResultado()`. El documento queda así: `pendiente` → **`rechazado`**, con `fe_detalle = "9022 - …"`. Las consecuencias:
- **Estado fiscal falso.** Según la propia spec, un rechazo no consume el número. Entonces un 9022 significa que MSL ya tiene ese número (certificado o en proceso), no que la DGI lo rechazó. Tras recargar, el badge dice "Rechazado" y el mensaje especial se pierde, porque solo vive en el estado local del componente. El riesgo real es que Aprobador crea que no hay documento fiscal y emita otro por la misma venta, es decir, un duplicado real ante la DGI.
- **Bucle en la UI.** Con `rechazado`, `accionDisponible()` ofrece "Reintentar certificación". Cada reintento vuelve a dar 9022.
- **Contradice lo pedido.** El encargo dice "un 9022 posterior se explica sin sobrescribir nada más", y el criterio de aceptación 6 dice "se muestra el mensaje especial". El test `test_un_404_seguido_de_9022_avisa_que_ya_estaba_certificado_sin_duplicar` (`tests/.../FeReintentoTest.php:304-316`) solo verifica `cufe` null y **no verifica `fe_estado`**, así que deja pasar este efecto.
- **SCRUM-1009.** Una NC de anulación por corrección en este estado quedaría `rechazado`, y 1009 retendría el reemplazo para siempre aunque la NC probablemente esté certificada.
- **La detección depende de una forma inventada de la respuesta.** El fixture (`422` + `estado: RECHAZADO`) nunca se capturó de MSL real. Si MSL manda el 9022 como `400` sin `estado`, el adapter lo mapea a `error_configuracion`. En ese caso `mensajeTrasReenvio()`/`resumen()` (`:285`, `:306`, que exigen `fe_estado === 'rechazado'`) no lo reconocen: el usuario recibe un mensaje genérico de configuración y el documento sale de `pendiente`.

**Corrección sugerida:**
1. Que `reenviar()`/`certificar()` devuelvan el `FiscalDocumentResult`. El código que se agrega en `certificar()` no cambia el comportamiento actual.
2. Si `$resultado->errores` contiene un 9022, detectarlo sobre los errores y no sobre `fe_detalle`, con algo como `preg_match('/(^|\D)9022\s*-/', …)`, sin importar el HTTP ni el `estado`. En ese caso: registrar la transmisión (ya se hace), **no llamar a `persistirResultado()`**, dejar `fe_estado` como estaba, auditar y devolver `codigo = ya_certificado_9022`.
3. Aplicar el mismo tratamiento en `recertificar()` desde `rechazado`, para no reemplazar el motivo original del rechazo por un 9022.
4. Ajustar el test para que verifique `fe_estado === 'pendiente'` después del 9022, y agregar un caso con 9022 como `400` sin `estado`.

---

## Suggestions 🟡

### S1. `throttle:6,1` no limita "por usuario", y el contador se comparte con otras rutas
`routes/admin-contab.php:44-46`
- `AuthenticateJwt` nunca llama a `setUserResolver`/`Auth::setUser`, así que `$request->user()` es `null`. En ese caso `ThrottleRequests::resolveRequestSignature()` arma la llave como `sha1(domain|ip)`. Como nginx usa `fastcgi_pass`, esa IP es la del cliente, así que el límite es **por IP**, no por usuario. El comentario de la ruta y la D3 de la spec dicen "por usuario".
- La llave no incluye la ruta. Por eso el contador **es el mismo** que usan `throttle:5,1` de `/api/ai/*`, `throttle:20,1` de soporte y LUMO, y los share links. Con la oficina de Atlantic detrás de un solo NAT, basta con que el personal use IA o Soporte para que Aprobador reciba un 429 al reintentar. Al revés, cada reintento también consume la cuota de IA de toda la oficina.
- Propuesta: un limiter con nombre en `AppServiceProvider`, `RateLimiter::for('fe-accion', fn (Request $r) => Limit::perMinute(6)->by('fe-accion:'.($r->attributes->get('jwt')['sub'] ?? $r->ip())))`, y `throttle:fe-accion` en la ruta. Agregar un test que verifique el 429 en la 7.ª llamada. El 6 es una decisión explícita de Luis (D3) y es técnico, no un umbral de negocio: puede quedarse en código.

### S2. Si falla el transporte durante el reenvío, se afirma "MSL encoló el documento", y en `recertificar` el `rechazado` pasa a `pendiente`
`FiscalCertificationService.php:292` y `certificar()`.
Con el circuito abierto o un timeout, `serviceUnavailableResult()` devuelve `estado: pendiente, errorTransporte: true`. Hoy `persistirResultado()` pasa `rechazado` a `pendiente` y agenda un polling que dará 404 hasta que se agote. El mensaje dice "MSL encoló el documento", y eso es falso porque MSL nunca lo recibió. En `recertificar`, si `errorTransporte` está en true, conviene no persistir, mantener el estado anterior y responder con `sin_cambios` y "MSL no está disponible". Es la misma política que ya usa `actualizarEstado` para el GET.

### S3. `sigue_en_cola` no necesita persistir: hoy duplica el polling y puede bajar a `pendiente` un documento que ya estaba `certificado`
`FiscalCertificationService.php:203-209`.
- Cuando MSL responde `PENDIENTE`, `persistirResultado()` no cambia nada útil, pero despacha **otra** cadena de `PollMslDocumentEstadoJob` (20 intentos). La cadena original sigue viva. Cada clic suma una cadena más; el throttle lo limita a 6 por minuto. El job es idempotente en lo sustancial, pero si ambas cadenas encuentran `CERTIFICADO`, el XML firmado se sube dos veces (queda un objeto huérfano en S3) y hay transmisiones duplicadas. El test `:288` fija este duplicado como comportamiento esperado.
- El job de polling **no toma el lock**. Si el job persiste `certificado` entre el GET manual y el `persistirResultado(pendiente)` manual, el documento vuelve a `pendiente` y su `fe_detalle` queda en null. Se recupera solo en el siguiente polling, pero pasa por un estado incoherente.
- Propuesta: en `sigue_en_cola`, solo registrar la transmisión y auditar, sin persistir. Como defensa adicional, que `persistirResultado()` nunca baje un documento de `certificado` a otro estado. Opcionalmente, que el job tome el mismo `Cache::lock` con `block(0)` y reintente más tarde si no lo obtiene.

### S4. Discriminar solo por `httpStatus === 404` es frágil
`FiscalCertificationService.php:192`.
Un `base_url` mal configurado o un cambio de path en MSL también devuelven 404, normalmente de un servidor web genérico. Eso dispararía un POST de reenvío, que es la acción irreversible. Conviene exigir además que el cuerpo sea el esperado: `ok === false` y `error` que contenga "no encontrado en la cola". Cualquier otro 404 debería caer en `sin_cambios` con el mensaje de configuración.

### S5. `actualizarEstado` arma el documento fiscal completo solo para hacer un GET, y reconstruye la serie
`FiscalCertificationService.php:173-181`.
- Si `paraNotaCredito()` lanza una excepción, por ejemplo porque la factura de origen ya no tiene CUFE, la respuesta dice "No se pudo consultar a MSL en este momento". El mensaje es engañoso: el problema es local y MSL nunca se consultó. Conviene separar los dos `try` y usar un mensaje distinto para cada uno.
- El GET usa la `serie` que vuelve a armar el builder a partir de la configuración actual. Si la serie cambió desde el envío original, consulta el documento equivocado, y el reenvío tras un 404 sale con la serie nueva. Para el GET, usar `$modelo->serie`, que es lo que ya hace el job, y `numeroFiscal($modelo->numero)`, sin armar el payload completo.

### S6. Un cambio de ambiente (`testing` → `production`) convierte un reintento en una emisión real
`bloqueoFiscal()` / `reenviar()` usan el ambiente **actual**. Un documento rechazado o pendiente en el sandbox, reintentado después del go-live, se enviaría a la DGI de producción como emisión fiscal real, algo que nadie pidió. Hay que confirmar si en prod existen hoy documentos FE con `pac_ambiente = testing`. Si los hay, se podría bloquear la acción cuando el `ambiente` de la última `fe_transmision` no coincide con el actual. Esta es una decisión de negocio que el Arquitecto debe llevar a Luis; no la resuelve un dev.

### S7. El lock no incluye el tenant
`FiscalCertificationService.php:268`.
`config/multitenancy.php` solo tiene `SwitchTenantSchemaTask` (no hay `PrefixCacheTask`), así que la llave `fe-recertificar:factura:{id}` es la misma en todos los tenants. Hoy hay un solo tenant, pero conviene agregar `Tenant::current()->slug` a la llave y sacar el 90 a una constante con nombre. El TTL de 90 s es coherente con el peor caso actual: GET 15 s + POST 15 s + subida a S3. El 90 es técnico, no de negocio, así que no hace falta llevarlo a configuración. El store está bien cubierto: `.env.example` usa `CACHE_STORE=redis`, que tiene locks atómicos; el default del config es `database`, y la tabla `cache_locks` existe; en tests se usa `array`, que también soporta locks. Falta que DevOps confirme `CACHE_STORE` en los `.env` reales de los EC2.

### S8. La auditoría queda corta respecto de la spec
`FiscalCertificationService.php:310-318`.
La spec pide `metadata: {tipo, http_status}`, y hoy solo se guarda `tipo_documento`. Además, en "Actualizar estado" que terminó en reenvío (404 → POST), la acción registrada es `estado_actualizado` y no queda constancia de que hubo un POST a la DGI. Propuesta: agregar `http_status` y `resultado` (`reenviado|resuelto|sigue_en_cola`) a `metadata`. El contenido no es sensible: solo `fe_estado`, sin payload, receptor ni API key. Bien.

### S9. El texto del 9022 afirma más de lo que se sabe
`mensajeTrasReenvio()` `:286` y el i18n `ya9022`.
"Ya fue certificado… No se duplicó nada ante la DGI" dice más de lo que prueba el código: 9022 significa "ya enviada a certificar". En dev y test, según `docs/msl-fe-scrum987/correo_msl_9026.md` §3, los dos entornos comparten la API key y se pisan los números, así que ahí un 9022 **no** implica que este documento esté certificado. Propuesta de texto: "MSL indica que ese número ya fue enviado a certificar (9022); verifica con MSL el estado y el CUFE."

### S10. Los mensajes del backend están solo en español
Tanto el `mensaje` de 200 como los de 409 son strings en español fijos en el código. Con la UI en inglés se ven mezclados. El frontend ya tiene `accion`, `fe_estado` y `codigo`, así que puede traducirlos con claves i18n y dejar el `mensaje` del backend como respaldo. Esto ya pasa en otras pantallas y no bloquea.

### S11. Estado del frontend
- `accionFeUsada` y el `resultado` interno sobreviven si el `orderId`/`invoiceId`/`notaId` del modal cambia sin desmontarse. Hoy todos los que llaman montan el modal de forma condicional y lo desmontan al cerrar, así que no se reproduce. Como protección barata: `key={invoiceId ?? orderId}` o `key={notaId}` en `<FeAccionFiscalBoton>`, o resetear `accionFeUsada` con un efecto cuando cambia el id.
- En `InvoiceDetailModal`, el `accion_fe` desaparece si otra mutación hace `setQueryData` con una respuesta que no trae el campo. Es el mismo patrón que ya existe con `fe_detalle`. En NC, `decision()` sí lo incluye. No se encontró un `setQueryData` de detalle de factura, así que hoy no aplica.
- Accesibilidad: al abrir la confirmación el foco no pasa al botón "Confirmar", y el grupo no tiene `aria-busy` mientras envía. Es menor.
- Un 429 muestra el `message` de Laravel en inglés ("Too Many Attempts."). Conviene mapear el 429 a una clave i18n.

### S12. Huecos de cobertura (backend y frontend)
Faltan, en orden de valor:
- 9022 → `fe_estado` sin cambios (B1).
- Throttle → 429 (S1).
- NC `pendiente` con "Actualizar estado", incluido el 404 → reenvío hacia `/v1/notas-credito`.
- `accion_fe` null en el detalle de una NC no aplicada y de una factura anulada.
- GET con 401/403 → `sin_cambios` sin POST (`Http::assertSentCount`).
- GET que responde `RECHAZADO` → persiste el rechazo.
- `documentoFiscal()` lanza en `actualizarEstado` → nada cambia.
- `fe_apagada` por proveedor distinto de `msl` o por tipo de documento deshabilitado, no solo por `apagado`.
- La relectura tras el lock: el modelo en memoria dice `rechazado` y la BD `certificado` → 409 sin llamar a MSL. Hoy nada lo prueba.
- En el frontend, que el modal llame a la mutación con `orderId`/`invoiceId` correctos y con la acción correcta.

Los tests existentes prueban lo que dicen. El de FE apagada verifica bien que `fe_estado` y `fe_detalle` no se tocan y que no se envía nada. El del lock es válido con el store `array`, porque comparte la instancia del mismo proceso, aunque no demuestra concurrencia real entre procesos, cosa aceptable a nivel de feature test. Los tests de vitest no son frágiles: usan `t` identidad y consultas por texto o rol.

---

## Well done 🟢

- **Decisión central correcta:** el reintento entra por el `certificar()` privado y deja fuera `certificarFactura()/certificarNotaCredito()`, así que nunca sobrescribe a `no_aplica` con la FE apagada. `bloqueoFiscal()` se evalúa dentro del lock, después del `refresh()`, y no modifica el documento. `refresh()` sobre un modelo con `$hidden = ['fe_detalle']` es seguro, porque `$hidden` solo afecta la serialización.
- **Parámetro `origen` con default `'certificacion'`:** las llamadas que ya existían (listener, `certificarFactura`/`certificarNotaCredito`) no cambian. `FeTransmisionRecorder` mapea bien `consulta_manual` → `consultar_estado`, y `reintento_manual` cae en `certificar`. Las dos cadenas entran en `origen varchar(20)`.
- **Inyección de `AuditService`:** no hay ningún `new FiscalCertificationService(` en `app/` ni `tests/`. Todo se resuelve por el contenedor, incluido `PollMslDocumentEstadoJob::handle()`.
- **Permisos:** las 4 rutas están bajo `primary_approver_only` (403 para Contabilidad, 401 sin token, ambos con test). `accion_fe` y `fe_detalle` se calculan solo si `$isApprover`, y los servicios que comparten Bodega y el job de correo no se tocaron. `decision()` también devuelve `accion_fe`, así que el `setQueryData` no desmonta el botón. `invoice_id` se resuelve dentro de la familia del pedido (`resolveInvoice` → `firstWhere` sobre `forOrder`), sin IDOR.
- **Sin filtraciones:** el `Log::error` de `actualizarEstado` registra solo `exception_class`, sin mensaje ni PII. `fe_detalle` pasa por `sanitizarDetalle()`, que redacta la API key. La auditoría guarda solo `fe_estado`.
- **Lock sin estado intermedio en BD:** está bien razonado, porque evita un `pendiente` huérfano con polling si el proceso muere. `release()` respeta al dueño del lock.
- **Frontend:** confirmación explícita antes de cualquier envío. El botón de confirmar queda deshabilitado mientras envía, sin doble envío, y hay test. Todo se renderiza como texto, también con test de XSS. La visibilidad depende solo de `accion_fe` del backend. El `resultado` sigue visible después de certificar. Las claves i18n están en es y en; no hay emojis; `role="status"` para el resultado.
- La relación con SCRUM-1009 está bien pensada: todo pasa por `persistirResultado()`, que es el único punto donde 1009 debería engancharse. Queda sujeto a B1.

---

## Verificación posterior requerida
Después de corregir B1 (y S1-S3 si se toman): FeReintentoTest completo más los tests nuevos, PHPStan L8 del proyecto completo y el gate de la suite completa **sin correr en paralelo con otra corrida sobre la misma BD**. Los commits van en español, con formato `tipo(scope): …`.

---

## Re-review (2026-09-30), solo el diff de correcciones

**Modo:** solo lectura. No se corrieron tests; se toma la evidencia que reportó el Arquitecto (FeReintentoTest 30/30, PHPStan L8, tsc, vitest 2106).

### Decision: **APPROVED** para pasar a Pre-QA, con 2 sugerencias no bloqueantes (R1 y R2)

| Hallazgo | Estado |
|---|---|
| **B1** 9022 | ✅ Resuelto. `enviar()` ensambla, envía y registra la transmisión sin persistir. `certificar()` = `enviar()` + `persistirResultado()`, igual que antes para la certificación inicial y el listener. `reenviar()` devuelve `ya_enviado_9022` sin tocar el estado ni `fe_detalle`, tanto en `recertificar` como en `actualizarEstado`. La detección ya no depende del HTTP ni de `estado`. Los tests verifican `pendiente`/cufe/detalle y el caso 400 sin `estado`. |
| S1 throttle | ⚠️ Resuelto solo en parte, ver **R1** |
| S2 transporte | ✅ `sin_cambios_transporte`: no persiste ni agenda polling |
| S3 sigue en cola | ✅ No persiste; ya no se encadena otro polling ni puede bajar un `certificado` a `pendiente` desde este camino |
| S4 404 | ✅ Solo reenvía si el 404 viene con "no encontrado" en `error` |
| S5 GET | ✅ Usa `$modelo->serie` + `numeroFiscal()`, como el job. Sin serie va directo al reenvío. Si el builder lanza, `error_configuracion` es el estado real (problema local) |
| S6 ambiente | ✅ Compara el ambiente de la última `fe_transmision` con el actual (`production`→`produccion`, resto→`pruebas`, igual que `PacProviderFactory`). También oculta `accion_fe`. Un documento sin transmisiones previas (anterior a SCRUM-987) no se bloquea, lo cual es aceptable |
| S7 lock | ✅ La llave incluye el tenant |
| S8 auditoría | ✅ `desenlace`, `http_status` y `reenviado_tras_404` |
| S9 texto 9022 | ✅ Suavizado en backend y en i18n es/en |
| S11 | ✅ `key` por documento en ambos modales y `aria-busy`. El foco queda pendiente (menor) |
| S10 | Pendiente, anotado (no bloquea) |

### Hallazgos nuevos

🟡 **R1. El limitador `fe-accion` sigue limitando por IP, no por usuario.**
Ver `app/Providers/AppServiceProvider.php` (nuevo `RateLimiter::for`) y `routes/admin-contab.php:44-46`.
Laravel ordena los middleware de la ruta según `$middlewarePriority` (`vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php:103-115`). `ThrottleRequests` tiene prioridad sobre `SubstituteBindings`, que es el primer middleware del grupo `api`. `SortedMiddleware` por lo tanto lo **mueve a la posición 0, antes de `AuthenticateJwt`**, que no está en esa lista. Cuando se evalúa el limitador, `$request->attributes->get('jwt')` todavía es `null`, así que siempre entra en el respaldo `$request->ip()`.
- **Lo que sí quedó resuelto:** la llave ahora es propia (`fe-accion:*`), así que ya no comparte el contador con IA, soporte, LUMO ni share links.
- **Lo que no:** dentro de la misma IP, Aprobador y superadmin comparten los 6/min. Además, un POST de cualquier usuario sin permiso desde la oficina consume ese cupo antes del 403.
- **Por qué el test no lo detecta:** usa una sola IP y un solo usuario, así que pasa igual.
- **El comentario queda falso:** tanto la ruta como el provider dicen "POR USUARIO".
- **Arreglo:** en `bootstrap/app.php`, `$middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\ThrottleRequests::class, prepend: \App\Shared\Http\Middleware\AuthenticateJwt::class)`. Es un cambio global: deja la autenticación antes que cualquier throttle, lo que además es más correcto. Agregar un test con dos usuarios Aprobador/superadmin: el segundo no recibe 429 cuando el primero agotó su cupo. Otra opción es mover el throttle a un middleware propio o hacer el chequeo con `RateLimiter::attempt()` en el controlador. Si no se corrige ahora, al menos hay que dejar el comentario en "por IP" y abrir un ticket de seguimiento. El impacto práctico es bajo: solo 2 roles usan la acción.

🟡 **R2. `\b9022\b` puede dar falsos positivos.**
Ver `esNumeroYaEnviado()`.
La regex se aplica también a `rawResponse['error']`, que es el texto largo con la regla que falló y puede citar valores del payload. Un RUC como `8-9022-…` o un monto `9022.50` cumplen `\b9022\b`, porque el guion y el punto son límites de palabra. En ese caso un rechazo real no se persiste y el usuario recibe el mensaje de 9022. Es fail-safe: no escribe un estado falso y la transmisión queda registrada. Aun así desinforma. Propuesta: exigir el formato de código de MSL, `/^\s*9022\s*-/` sobre cada elemento de `errores` y sobre `error`.

Sin hallazgos nuevos en seguridad, permisos, PII ni concurrencia del lock.
