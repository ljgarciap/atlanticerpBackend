# Senior Review — Fase 17b (etiqueta de identificación por país) y 17c («Asociado») — 2026-11-03

Ramas apiladas sobre 17a: backend `fase17b/etiqueta-identificacion` (`6f08ed6`) → `fase17c/asociado` (`6b7ef96`, `5daba28`); frontend `9bca202` → `56e64e0`. Spec: `docs/specs/fase17-identificacion-unica-y-asociado.md`. **Aprobadas, 0 bloqueantes.**

## 17b
- `TenantRegion::fiscalIdLabel()/fiscalIdPhrase()` reutilizan `TaxIdRulesRegistry` (una sola fuente por país). Mensajes de validación, encabezado del libro de facturas (Excel), PDF internos y de cotización usan la etiqueta; Panamá conserva el texto exacto (verificado: «Ingresa el RUC o la cédula a validar.»).
- Frontend: `{{fiscalId}}`/`{{fiscalIdFull}}` como variables por omisión de i18n (mismo mecanismo que `{{tax}}`), publicadas desde `user.fiscal_id`. El contrato falla si reaparece un «RUC» fijo en un texto traducido.
- **Se deja con «RUC» a propósito:** textos de la DGI / factura electrónica de Panamá (`ReceptorFiscalGuard`, `invoice-pdf-fiscal` salvo la etiqueta del emisor) y la etiqueta `'RUC / Cédula'` que el backend manda en `missing` de la cotización (el frontend la usa como clave de mapeo, no como texto).
- Test con el tenant en Colombia (`FiscalIdLabelByCountryTest`).

## 17c
- Entidad = «Asociado» en español e inglés (menú, detalle, campo de cotización, comisiones externas, rol del contacto, mensajes del backend, concepto «Comisión asociado …», etiqueta del dominio de tipos).
- **No cambia (decisión «solo lo visible»):** tabla `architects`, rutas, `architect_id`, permisos, las claves de API `arquitectos` y `pendiente_factura_arquitecto`, el tipo «Arquitecto» del catálogo y el CRM (vocabulario propio, Fase 7c).
- Contratos con excepciones con dueño que solo pueden encogerse: `asociadoVocabulario.test.ts` (i18n) y `AsociadoVocabularioTest` (backend).

## Hallazgos
1. **(Abierto, decisión de Luis)** El segmento de clientes «B · Arquitecto o Diseñador» sigue nombrando el oficio; es una categoría de cliente distinta del asociado. Candidato a pasar a un catálogo por empresa.
2. **(Abierto)** Los movimientos de tesorería ya guardados conservan el concepto «Comisión arquitecto …» (dato histórico, no se migra).
3. **(Abierto)** `DemoDataset::arquitecto()` genera nombres demo «Arquitecto N»; es dato de ejemplo, no texto del producto.
4. **(Aceptado)** El e2e ejecutable de los flujos no cubre textos; la verificación de pantalla es por los contratos de i18n.

## Verificación
Backend: suite completa de 17b 5211 tests / 0 fallos; la de 17c 5213 tests con 1 fallo (un test que esperaba «Arquitecto» en el mensaje), corregido y verde; PHPStan 0. Frontend: 246 archivos / 2926 tests, `tsc` limpio.
