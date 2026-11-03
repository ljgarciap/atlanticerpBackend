# Senior Review — Fase 17a: identificación fiscal única por tenant (2026-11-03)

Alcance: rama `fase17a/identificacion-unica` (backend `a33561c` + `b3fe4ad`, frontend `735da26`). Spec: `docs/specs/fase17-identificacion-unica-y-asociado.md`. **Aprobada, 0 bloqueantes.**

## Qué se revisó
- `SubClient`: hook `saving` + `taxIdKey()`; `DuplicateSubClientTaxIdException` (409 con el cliente existente); migración `2026_11_11_100000`; alineación de proveedores; modal de alta del frontend; fixtures.
- Búsqueda de escrituras que esquiven el hook (`Builder::update`, `DB::table`): **ninguna en `app/`** (solo `category` vía `update()` del modelo, que no cambia la clave).

## Hallazgos
1. **(Corregido) Carrera:** dos altas simultáneas podían pasar la verificación del modelo y la segunda recibía un 500 del índice único. Ahora `bootstrap/app.php` traduce esa violación (solo la del índice `sub_clients_tax_id_key_unique`) al mismo 409 `duplicate_tax_id`, sin el cliente existente. Test que prueba que el índice respalda la regla.
2. **(Corregido) Fixtures con RUC aleatorio de 4 dígitos** (creaban hasta 25 subclientes por test): colisión por cumpleaños, intermitente. Todos pasaron a `Tests\Support\UniqueTaxId`.
3. **(Aceptado) El 409 revela la razón social del cliente existente** a quien tenga permiso de alta. Los clientes ya son globales y legibles para ese mismo rol; no se amplía la exposición.
4. **(Aceptado) El `down()` de la migración** quita índice y columna; la clave se recalcula al subir. Sin pérdida de datos.
5. **(Abierto, fuera de alcance)** Proveedores: la unicidad sigue en la capa de validación, sin índice en BD (decisión de la spec §1.5).

## Verificación
Backend: suite completa 5208 tests (2 errores en la primera corrida: ambos colisiones aleatorias, ya corregidas; el intermitente conocido de `orders_order_number_root_uq` no es de esta fase), PHPStan 0. Frontend: 244 archivos / 2921 tests, `tsc` limpio. e2e de flujos: 88 pasos sobre la BD resembrada con la migración.
