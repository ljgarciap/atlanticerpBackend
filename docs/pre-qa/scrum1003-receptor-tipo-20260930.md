# Pre-QA SCRUM-1003 — Tipo de cliente ante la DGI (Contribuyente 01 / Consumidor Final 02) — dev.atlanticerp.ai — 2026-09-30

Backend a0a841b, frontend 6b0bdc2 (los desplegados en dev; el árbol local ya trae 2 commits más, ver MEDIO-1). `fiscal_settings`: `pac_provider=msl`, `pac_ambiente=testing` (leído por SQL, NO modificado). Sin push/deploy/Jira, SQL solo lectura. Tope de documentos fiscales: 1 (usado 1, 0 rechazos).
Cuentas: Aprobador (mbekhar@), Contabilidad (conta@), [persona] (vendedordisenador@test.com, vendedor), operaciones@, almacen@ (jefe de bodega, solo para llevar el pedido a por_despachar).
Nota: el informe de Senior Review `docs/reviews/scrum1003-receptor-tipo-20260930.md` aparece solo como archivo sin versionar; lo leí del árbol.

## Documento fiscal gastado
| Documento | Resultado | Número | CUFE |
|---|---|---|---|
| F-0027 (invoice 36, pedido 72 PED-2026-0052, 19,66, Consumidor Final) | CERTIFICADA, HTTP 200, 2368 ms (transmisión id 14) | ILU 0027 | `FE0120000155704849-2-2021-3200022026100100000000270090325619399065` |

Un solo documento. No se hizo NC ni ninguna otra factura.

## Veredicto: NO LIMPIO (1 MEDIO: el control "solo Aprobador y Contabilidad" no está efectivo en lo desplegado; el resto limpio)

## Resultado por criterio

### 1. Alta (API y UI): OK
- API: `receptor_tipo` 03, 04 y `xx` -> 422 ("El receptor tipo seleccionado no es válido."). `01` sin RUC/DV -> 422 (tax_id y DV obligatorios). `02` con RUC `8-123-456` y DV `7` enviados -> 201, guardado `tax_id=''`, `tax_id_dv=NULL` (se ignoran). Sin tipo -> guardado explícito `01` (sub 7714).
- UI (Aprobador, es): selector "Tipo de cliente ante la DGI", default Contribuyente (RUC y "Validar RUC" visibles); con Consumidor final se ocultan RUC, DV y "Validar RUC", aparece el texto de ayuda, el guardado queda habilitado sin RUC y el cliente se crea; el detalle del cliente muestra "Consumidor final" en el subcliente. "Nuevo subcliente" abre con default Contribuyente y RUC visible. "+ Crear cliente" desde Cotización: selector presente, default 01, con 02 desaparece "Validar RUC".
- Directorio de Datos fiscales (Contabilidad y Aprobador): filas de 02 muestran "Consumidor final" en la columna RUC; el 02 con factura certificada sale "Bloqueado".

### 2. Edición: OK
- 01->02 (Aprobador, mandando RUC/DV: se vacían), 02->01 sin RUC -> 422 y sin DV -> 422 (UI: guardar deshabilitado si RUC vacío), 02->01 completo (Contabilidad) -> 200. Tipo 03 en edición -> 422.
- 409 con el mismo mensaje claro sobre el subcliente 7710 (certificado) y sobre el 7713 (Consumidor Final recién facturado, 02->01), sin cambios.
- `audit_logs`: `ventas_diseno.sub_client.updated` con `receptor_tipo`, `tax_id`, `tax_id_dv` antes/después y `user_id` de Aprobador (16) y Contabilidad (21); `created` incluye `receptor_tipo`.

### 3. Cadena completa Consumidor Final: OK (B1 cerrado)
- Sub 7713 (02, sin RUC). Cotización 328: `validate` pide solo Proyecto/Contacto/Fecha de entrega/Ítems (NO "RUC / Cédula"); tras completarlos `generate` y `confirm` funcionan (COT-2026-0117). PDF de la cotización: "RUC: Consumidor final" (no queda vacío). Etapa Aprobado -> pedido PED-2026-0052 (order 72) -> asignar picker, picking, registrar entrega (almacen@) -> `por_despachar` -> facturar (Contabilidad) -> F-0027.
- (a) fe-preview "antes de certificar": NO es posible en este flujo. `POST /invoices` dispara `CertifyInvoiceOnCreated` de forma síncrona (certifica en el mismo request); no hay ventana. Lo que sí comprobé: `GET /invoices/72/fe-preview` tras crear devuelve `receptor {tipo:"02", nombre:"CONSUMIDOR FINAL", pais:"PA"}` sin `ruc`/`dv`/`email`/`direccion`, ítems `tasaITBMS "01"`, pago 19,66. El payload realmente enviado (visor de Aprobador, transmisión 14) es idéntico: `receptor {pais:"PA", tipo:"02", nombre:"CONSUMIDOR FINAL"}`. Nota: va `pais:"PA"`, no mencionado en la spec.
- (b) MSL/DGI lo ACEPTÓ: HTTP 200, `estado CERTIFICADO`, CUFE arriba, `autorizacion 0000155704849-2-202126591120930200509873`. Sin requisito ni tope extra para consumidor final en el sandbox (importe 19,66). S4 de la review: sin hallazgos, pero con un solo documento chico no se probó un monto grande.
- (c) PDF de la factura (Contabilidad): se genera sin RUC (200), conserva el NOMBRE REAL ("PREQA-1003 Consumidor Real Perez"), trae "Consumidor final — sin RUC" y no imprime "CONSUMIDOR FINAL".
- (d) Libro de facturas (JSON y Excel): F-0027 aparece con `ruc: null`, cliente real, base 18,37, ITBMS 1,29; resumen sin romperse. Estado de cuenta (pantalla JSON, PDF y Excel, master 7709): movimiento F-0027 con saldo 19,66, sin errores.

### 4. Regresión 01: OK (con una prueba no ejercible)
- fe-preview de una factura 01 existente (pedido 65, F-0026): `receptor {tipo:"01", ruc:"155704849-2-2021", dv:"32", nombre:"DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA", pais:"PA"}`, igual que antes. PDF de F-0026 sin cambios (cliente y RUC impresos).
- Un 01 sin RUC que bloquee el PDF (422 "falta el RUC del cliente"): NO se pudo ejercer en vivo; en dev no existe ninguna factura 01 con `tax_id` vacío y el único sin RUC (7713) ya es 02 y certificado. Verificado solo por lectura de código (`AdminContInvoiceController::pdf`: el bloqueo se mantiene salvo `cliente_receptor_tipo === '02'`).
- Comando `clients:revalidate-tax-ids --dry-run --rate=20 --from-id=7711` (4 filas): el 02 (7713) sale `consumidor_final`, sin campos oficiales, `discrepancias=ninguna`, sin RUC consultado; BOM presente; huella (sub_clients, invoices, fe_transmisiones, audit_logs) idéntica antes/después. (El resumen impreso al final no cuenta `consumidor_final`; informativo.)

### 5. Consola / i18n / temas: OK
Sin errores de consola en las pasadas (solo 4xx esperados). es/en sin claves crudas (textos "Customer type for the DGI", "Final consumer", "Invoiced as CONSUMIDOR FINAL..."). Modo oscuro (clase `dark`) legible en el modal de edición. Sin emojis.

## Hallazgos
| Sev | Hallazgo |
|---|---|
| MEDIO-1 | **"Solo Aprobador y Contabilidad pueden marcar un cliente como Consumidor final" (decisión de Luis 2026-09-30) NO está efectiva en lo desplegado.** En dev, [persona] (vendedor, sin `ventas_diseno.edit.client_tax_data`): (1) en el modal "Crear cliente" la opción "Consumidor final" está habilitada y sin el aviso "Solo Aprobador y Contabilidad..."; (2) por API `POST /clients` con `receptor_tipo=02` -> 201 (creé el sub 7713 con su token) y `PATCH .../sub-clients/7714/tax-data` pasando 01->02 -> 200 (lo restauré a 01 con Contabilidad). El repositorio local ya trae los commits `3b7ecb0` (frontend) y `51164384` (backend, 403 al pasar a 02 sin el permiso), pero lo desplegado en dev (backend a0a841b / frontend 6b0bdc2) no los incluye. Acción: desplegar y reverificar (403 de [persona] en API, opción deshabilitada en UI; Aprobador y Contabilidad sí). Además, con ese gate Contabilidad solo podrá marcar 02 desde la pantalla Datos fiscales (no tiene `ventas_diseno.write` para el alta). Es la única razón del NO LIMPIO. |
| BAJO-1 | Mensajes 422 de tipo inválido distintos entre endpoints: en `POST /clients` sale el genérico "El receptor tipo seleccionado no es válido." y en el PATCH el específico "El tipo de cliente ante la DGI debe ser Contribuyente o Consumidor Final."; también el 422 de RUC faltante en el alta sale como "El campo tax id es obligatorio a menos que receptor tipo esté en 02." (inglés parcial) mientras en el PATCH sale "El RUC o cédula es obligatorio.". Faltan los `messages()` de `ReceptorTipo::mensajes()` en el request del alta. |
| BAJO-2 | El receptor enviado a MSL lleva `pais:"PA"` también para 02; MSL lo aceptó. Informativo. |
| BAJO-3 | Crear un cliente por UI crea automáticamente una tarjeta Lead en Pipeline (comportamiento existente); anotado por los datos que quedan. |

## Lo que sí funciona
Selector con default 01, ocultar RUC/DV/Validar, alta sin RUC, 03/04/xx -> 422, 02 ignora RUC enviado, 02->01 exige RUC y DV, 409 en subclientes con documentos certificados (incluido el CF facturado), auditoría con `receptor_tipo`, cotización de un CF sin RUC (B1), PDF de cotización "Consumidor final", pedido hasta factura, F-0027 certificada por MSL con receptor 02 sin RUC/DV, PDF de factura sin RUC con nombre real y leyenda, libro de facturas y estado de cuenta con RUC vacío, fe-preview de 01 sin cambios, comando que salta a los 02, BOM del CSV.

## Datos que dejé en dev (nada borrado)
- Clientes master: 7709 "PREQA-1003 CF Cliente" (sub 7713 "PREQA-1003 Consumidor Real Perez", 02, facturado F-0027), 7710 "PREQA-1003 Contrib Cliente" (sub 7714 "PREQA-1003 Contribuyente SA", 01, RUC de la guía/DV 32 — restaurado tras las pruebas), y "PREQA-1003 UI CF" (sub "PREQA-1003 Persona UI", 02, creado por UI con Aprobador) con su tarjeta Lead; contactos solo con teléfono (6000-1003/1004/1005), sin email.
- Cotización 328 COT-2026-0117, tarjeta de pipeline 365, proyecto 339 "PREQA-1003 Proyecto CF", archivos `q328.pdf` (firmada y comprobante de aprobación) subidos a S3, pedido 72 PED-2026-0052 (entregado a por_despachar, picker Auxiliarbodega), factura 36 F-0027 certificada (ILU 0027), transmisión 14. Sus `audit_logs`.
- CSV `atlantic-20260930-200707.csv` en el storage privado de dev.
- Specs locales sin commitear: `atlanticerp-frontend/e2e/_preqa_scrum1003_ui.spec.ts`, `_preqa_scrum1003_quote.spec.ts`; capturas `e2e/.tmp/s1003-*.png`.

## Sin cubrir
- El control de permiso de Aprobador/Contabilidad (ver MEDIO-1) hasta que se despliegue `51164384`/`3b7ecb0`.
- fe-preview estrictamente ANTES de certificar (la certificación es síncrona al crear la factura; requeriría pac apagado).
- NC sobre la factura de un Consumidor Final y una segunda factura (tope de 1 documento); monto grande o consumidor final sobre el umbral de Aprobador.
- Factura 01 sin RUC bloqueando el PDF (no hay fixture; solo lectura de código).
- Pantalla de libro/estado de cuenta en navegador (probados por API/exportaciones); tema oscuro de los demás modales.
- Observaciones de SCRUM-990 (Bodega/Zona Libre/Lafayette): nada raro en lo recorrido (picking y entrega de Bodega funcionaron normales); no se exploró Zona Libre.


---
# Re-verificación permiso (backend 54757f72, frontend fde5116, dev, 2026-09-30)

0 documentos fiscales nuevos (solo F-0027 de la pasada anterior), `pac_ambiente` sin tocar, nada borrado. Sin cuenta superadmin de prueba: no verificado.

## Resultado
1. **MEDIO-1 CERRADO.** [persona] (sin `ventas_diseno.edit.client_tax_data`): `POST /clients` con `receptor_tipo=02` -> 403 "Solo Aprobador y Contabilidad pueden marcar un cliente como Consumidor final." sin crear nada (conteos de master_clients y sub_clients iguales); `POST /clients/7709/sub-clients` y `POST /sub-clients` con 02 -> 403; `PATCH tax-data` 01->02 -> 403 sin cambios (también en 7715 tras pasarlo ella a 01). UI es/en: en el alta y en la edición de un 01, "Consumidor final"/"Final consumer" sale DESHABILITADA con la nota "Solo Aprobador y Contabilidad..."; en la edición de un 02 existente la opción queda habilitada sin nota, y pasando a 01 en el modal sigue habilitada para volver a 02. [persona] SÍ edita la razón social de un 02 existente (200), pasa un 02 a 01 con RUC/DV (200) y crea un Contribuyente (201).
2. **Aprobador y Contabilidad:** Aprobador pasa 01->02 por API (200) y su modal de alta muestra la opción habilitada y sin nota; Contabilidad marca 02 desde la pantalla Datos fiscales de clientes (sin `ventas_diseno.write`): guardado, la fila pasa a "Consumidor final"; 02->01 con guardar deshabilitado hasta completar RUC y DV, y por API sin RUC -> 422; lo restauró a 01 con 155704849-2-2021 / DV 32.
3. **422 en español:** sin RUC -> "El RUC o cédula es obligatorio." y "El DV es obligatorio."; `receptor_tipo=03` -> "El tipo de cliente ante la DGI debe ser Contribuyente o Consumidor Final." BAJO-1 cerrado.
4. **Regresión:** alta de Contribuyente por [persona] (API) y por Aprobador (pasada previa), edición y directorio sin errores de consola.

## Datos nuevos / restaurados
Creado: sub 7716 "PREQA-1003 contrib [persona]" (01, bajo el master 7710), contacto solo con teléfono. 7715 "PREQA-1003 Persona UI" restaurado a 02 con su nombre original; 7714 restaurado a 01 con RUC de la guía/DV 32. Specs sin commitear: `atlanticerp-frontend/e2e/_preqa_scrum1003_gate.spec.ts`, `_preqa_scrum1003_gate_mark.spec.ts`.

## VEREDICTO FINAL: LIMPIO
Sin cubrir (igual que antes): fe-preview antes de certificar, NC/segunda factura sobre el Consumidor Final, 01 sin RUC bloqueando el PDF en vivo, superadmin.
