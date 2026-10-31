# Flujo: comisiones externas (arquitectos) en Admin & Contab

Continuación de [compensaciones, incobrables, caja chica y arqueo](admin-contab-cierres.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-10-31 con las cuentas demo de cada rol y un proyecto propio creado por el flujo (`npm run e2e:flujos`, archivo `admin-contab-comisiones-externas.spec.ts`).

## Qué es
Un proyecto referido por un **arquitecto** genera una comisión para él. El arquitecto se toma de la **cotización más reciente del proyecto que tenga arquitecto** (`quotes.architect_id`); si ninguna lo tiene, el proyecto no aparece [C]. El porcentaje por defecto es el de Configuración Fiscal (`comision_externa_default_percent`, 10 % en el demo) [E].

## Quién decide qué
- **Ver** (resumen, arquitectos, cuentas, descargas): Administración y Gerencia (`admin_contab.read`); vendedor y Líder de Compras reciben 403 [E].
- **Datos fiscales, marcar pagado, proponer porcentaje, recordatorio**: Contabilidad y Asistente (`admin_contab.records.manage`); el vendedor recibe 403 [E].
- **Subir cuenta de cobro y comprobante de retención, fijar la cuenta de pago**: solo Contabilidad y Asistente (`admin_contab.operations.manage`, sin el carve-out de Gerencia) [C]; el vendedor recibe 403 [E].
- **Decidir el porcentaje propuesto**: solo el aprobador primario configurado; Contabilidad y otra cuenta de Gerencia reciben 403 [E].

## 1. Cuándo se genera la comisión
«Todo o nada»: el estado del proyecto es `aun_no_generada` mientras **no esté totalmente facturado y cobrado** (facturado ≥ monto del proyecto y cobrado ≥ facturado). Un cobro parcial o una factura parcial nunca generan comisión [C]. Al facturar «completo» y confirmar el cobro, pasa a `pendiente_factura_arquitecto` con `estado_cobro_cliente = cobrado` [E]. Solo se llega a `pagada` con una fecha de pago real, nunca automática [E].
- **Base del cálculo**: el monto del proyecto de la tarjeta, que es el total de la cotización **con** el impuesto incluido (345,59 → comisión 34,56 al 10 %) [E].

## 2. Datos fiscales del arquitecto
`PUT admin-contab/commissions/external/architects/{id}/fiscal-profile {empresa, ruc, regimen_fiscal}`; régimen ∈ `exento`, `con_itbms`, `retencion_50`. Régimen inventado o RUC vacío → 422 [E].
- **Sin régimen no se calcula el impuesto** (`impuesto` y `total` nulos; nunca se asume exento) [E].
- `con_itbms`: impuesto = comisión × la tasa vigente (parámetro, no una constante); `exento`: 0; `retencion_50`: la mitad de ese impuesto [E].
- Completos los datos, el arquitecto muestra `datos_fiscales_completos` y sus totales `generada` / `pendiente` [E].

## 3. Cambio de porcentaje
`POST …/projects/{cardId}/percent/propose {porcentaje, motivo}` y `…/percent/decide {approve, motivo_rechazo?}`.
- Propone Contabilidad; porcentaje 0 o > 100 y motivo vacío → 422 [E]. Solo **una** propuesta pendiente por proyecto (otra → 422) [E].
- Mientras está pendiente rige el porcentaje anterior y **no se puede marcar pagado** (422) [E].
- Rechazar exige motivo (422 sin él) y deja el porcentaje igual; la comisión se recalcula solo si el aprobador aprueba (10 → 12 %) [E]. Decidir sin propuesta pendiente → 422 [E].

## 4. Pago
1. `POST …/cuenta-cobro` (archivo, 201) y `GET …/cuenta-cobro` (URL firmada que se descarga) [E]. Sin cuenta de cobro, marcar pagado → 422 [E].
2. Con régimen `retencion_50` hace falta además `POST …/comprobante-retencion`; sin él, 422 [E]. Con otro régimen el comprobante no aplica [C].
3. `PUT …/cuenta-pago {bank_account_id}` fija desde qué cuenta se paga [E].
4. `POST …/mark-paid`: estado `pagada` con `fecha_pago`; el total «pagada» del arquitecto sube [E]. Registra además el movimiento de caja correspondiente [C].
5. **Ya pagada**: no se paga otra vez, ni cambia la cuenta de pago, ni se propone otro porcentaje (todo 422) [E].
- **Recordatorio** (`POST …/remind`): avisa al correo del arquitecto; solo mientras esté pendiente de factura (pagada → 422) y exige que el arquitecto tenga correo [E][C].

## Candidatos a cambio o mejora
1. **La fila del proyecto y los totales del arquitecto no coinciden sin datos fiscales**: el proyecto ya muestra una comisión (34,56) pero `generada` y `pendiente` del arquitecto valen 0 hasta completar su régimen. Consistente con «no calcular sin régimen», pero sorprende a quien suma. [E]
2. **La comisión se calcula sobre el total con impuesto incluido**, no sobre la base imponible. Es una decisión de negocio que conviene confirmar. [E]
3. **El estado se llama `pendiente_factura_arquitecto`** en la API y la interfaz lo muestra como «pendiente de factura»; el nombre interno engaña (quien factura es el arquitecto, no la empresa). [E]
4. **Pagar no exige que la cuenta de pago esté fijada**: el pago se registra sin cuenta aunque el campo exista. [C, por confirmar]
5. **Cuenta de cobro y comprobante de retención aceptan cualquier PDF/imagen**, sin validar contra el monto ni el régimen. [C]
