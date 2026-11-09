# Flujo: técnicos, herramientas e insumos de Servicios

Continuación de [Servicios: tickets](servicios-tickets.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-02 con las cuentas demo de cada rol (`npm run e2e:flujos`, archivo `servicios-tecnicos-herramientas.spec.ts`).

## Quién ve y quién escribe
| | Vendedor | Técnico (interno) | Líder de Servicios | Gerencia | Contabilidad / Líder de Compras |
|---|---|---|---|---|---|
| Técnicos externos (ver) | 403 | sí | sí | sí | 403 |
| Técnicos externos (alta, tarifa, estado) | 403 | 403 | sí | sí | 403 |
| Técnicos internos, herramientas, insumos (ver) | sí | sí | sí | sí | 403 |
| Técnicos internos, herramientas, insumos (escribir) | 403 | 403 | sí | 403 | 403 |
| Kardex de herramientas | 403 | 403 | sí | sí | 403 |
[E] Gerencia puede dar de alta técnicos **externos** pero **no** internos, ni tocar herramientas e insumos.

## 1. Técnicos externos
- Alta: `nombre`, `empresa` y `tarifa_dia` obligatorios; tarifa no negativa y correo con formato (422) [E].
- **La tarifa y su historial solo los ven quienes administran la operación de campo**: el técnico interno ve al externo sin `rate_history`; el Líder de Servicios y Gerencia, con él [E]. Cada cambio de tarifa se agrega al historial [E].
- Estado `active` / `inactive` (otro valor → 422); los contadores del listado (`counts`) se mueven en consecuencia [E]. Desactivar puede exigir confirmación (`confirm`) cuando tiene trabajo pendiente [C].
- No se borran [C].

## 2. Herramientas
- **No hay alta directa**: una herramienta nace por **solicitud de compra** (`POST tools/purchase-requests {nombre, cantidad}`) y se recibe con `POST …/{id}/recibir`, que crea **una unidad por pieza**, cada una con su `codigo_unico`, en buen estado y sin asignar («Bodega») [E]. Recibir dos veces → 409 [E].
- La solicitud exige nombre o `tool_id` y cantidad ≥ 1; una **reposición** usa `tool_id` (inexistente → 422) [E]. No genera una orden de compra en Compras: solo queda «solicitado» hasta que se recibe [C].
- **Asignación** (`PATCH tools/{id}/asignacion`) a un técnico interno; uno inexistente → 422 [E]. La búsqueda por código encuentra la unidad [E].
- **Estados** (`PATCH tools/{id}/estado`): `good`, `damaged`, `worn`, `lost` (otro → 422) [E]. Dañada o perdida **atribuye `responsable_incidente`** al técnico que la tenía («Sin asignar al momento del incidente» si estaba en bodega); el desgaste nunca atribuye responsable; volver a buen estado lo limpia [E].
- **Kardex de herramientas**: ingreso, daño, pérdida y desgaste quedan registrados; solo Líder de Servicios y Gerencia lo ven [E].

## 3. Insumos
- Un insumo es un **producto del catálogo** al que se le fija un mínimo (`POST insumos {catalog_product_id, minimo, cantidad_inicial}`); reusa el Kardex y las órdenes de Bodega/Compras, sin tabla de existencias propia [C].
- Mínimo negativo, producto inexistente o campos faltantes → 422; un producto **sin proveedor** no se puede agregar; uno ya agregado no se repite (422) [E].
- **La cantidad inicial no son existencias**: queda como **solicitud de compra pendiente**; el insumo nace `bajo_minimo` con 0 disponibles hasta que se reciba la compra [E].
- `POST insumos/{id}/solicitar {cantidad}` crea otra solicitud (201); cantidad 0 → 422; insumo inexistente → 404 [E].

## 4. Técnicos internos
- Alta (`servicios.tickets.manage`): `nombre` y una `especialidad` del catálogo `technician_specialty` (otra → 422) [E]. **Es una ficha suelta, sin usuario del sistema asociado** (`user_id` nulo en las altas manuales) [E].
- **La edición es un `PUT` completo**: sin reenviar el correo, se borra [E]. La baja es lógica (204) y la ficha desaparece de la lista; repetirla → 404 [E].
- Panel: estadísticas, agenda y visitas de hoy las ve todo el módulo, incluido el vendedor [E].
- **SLA por tipo de ticket** (`installation`, `warranty`, `claim`, `retrofit`, en días): lo lee todo el módulo; solo Gerencia lo cambia (el Líder de Servicios recibe 403) [E].

## Candidatos a cambio o mejora
1. ~~**Un insumo nuevo siempre nace «bajo mínimo»**~~ — **Fase 30**: `existencia_inicial` opcional entra como Entrada a la Reserva. (Original:): la cantidad inicial queda como solicitud, no como existencia, así que no se puede registrar un stock que ya se tiene. [E]
2. ~~**Los insumos no se pueden editar ni dar de baja**~~ — **Fase 30**: `PATCH insumos/{id}` (mínimo) y `DELETE` (desactiva). (Original:) (no hay rutas para cambiar el mínimo ni quitar uno). [C]
3. **La ficha del técnico interno no queda ligada a un usuario del sistema**: las altas manuales no crean ni vinculan usuario (`user_id` nulo), mientras que los técnicos del demo sí tienen uno; conviene aclarar cómo debe vincularse una ficha nueva (ver también el candidato 4.4 de la lista priorizada). [E]
4. ~~**El `PUT` completo de técnico interno borra~~ — **Fase 30**: `PATCH internal-technicians/{id}` parcial. (Original:) ** los campos que no se reenvían** (correo, teléfono). [E]
5. ~~**Recibir una herramienta no se cruza~~ — **Fase 30**: exige una `referencia` (texto) que se guarda con la recepción. (Original:) ** con una compra real**: basta un clic del Líder de Servicios para crear las unidades; no hay orden de compra ni comprobante. [C]
6. ~~**Gerencia no puede corregir lo que ve**~~ — **Fase 30**: permiso `servicios.assets.manage` (Líder y Gerencia). (Original:): ve herramientas e insumos pero cualquier cambio requiere al Líder de Servicios. [E]
7. ~~**Las herramientas no se dan de baja**~~ — **Fase 30**: `DELETE tools/{id}` desactiva (422 si está asignada). (Original:) (solo cambian de estado) y sus códigos únicos no se reutilizan; los datos de prueba se acumulan. [E][C]
