# Review: Fase 4a — datos del cliente anterior fuera del seed por defecto y de los PDF
**Date**: 2026-10-25
**Reviewer**: Senior Reviewer
**Decision**: ✅ Approved (0 bloqueantes, 5 sugerencias)

## Summary
Backend PR #8 y frontend PR #8. Cumple la spec (`docs/specs/fase4-dataset-demo-generico.md` §3.3 y §9): `DatabaseSeeder` en tres niveles, 7 seeders de datos del cliente sin
llamador y con valores de ejemplo, 6 seeders de tickets Jira y un comando QA borrados, PDF de cotizaciones con el bloque de pago y el pie leídos de los datos de la empresa,
cuenta de cobros por tipo y no por nombre de banco, y tests de contrato en ambos repos. CI en verde (backend 16m44s, frontend build y tests); suite local 6188 tests / 0 fallos,
frontend 3112 tests, PHPStan 0.

## Findings

### 🔴 Blockers
Ninguno.

### 🟡 Suggestions (non-blocking)
1. **`database/seeders/DatabaseSeeder.php` / `ZonaLibreProviderSeeder`** — el flujo de Zona Libre (Bodega → Compras) depende de `compras_settings.zona_libre_provider_id` y
   **no hay API ni pantalla que lo configure**: solo lo escribía ese seeder. En un tenant nuevo el flujo responde 422 «No hay un proveedor de Zona Libre configurado» y no se
   puede salir de ahí sin tocar la BD. Degrada con mensaje, no rompe, pero es una función inalcanzable. Decidir: pantalla/endpoint de configuración (cabe en Fase 5 o 7) o
   documentar el paso manual. El proveedor sembrado además era «BK INTERPRISES S.A.» (cliente anterior).
2. **`PaymentService::defaultBankAccountFor()` / `ArchitectCommissionService::defaultBankAccount()`** — cambio de comportamiento: antes exigía banco «Banco General»/«Banistmo»;
   ahora toma la primera cuenta activa del tipo. Con una cuenta por tipo da lo mismo; con varias corrientes elige la de menor id, sin criterio de negocio. Si importa, una columna
   «predeterminada» sería la solución de datos.
3. **`DatabaseSeeder.php:22`** — el comentario lista `AlmacenEquivalencia` entre los seeders «sin llamador», pero ese seeder no se llamaba antes de este PR. Inexacto; quitarlo del comentario.
4. **`QuotePaymentInstructions`** — imprime el número de cuenta completo en cotizaciones que pueden ir a clientes externos. Ya ocurría con el texto fijo; ahora depende del dato cargado, así que
   conviene que quien cargue la cuenta sepa que sale en el PDF (un campo «mostrar en cotizaciones» lo haría explícito).
5. **Abierto de la spec (no de este PR)** — el emisor del sandbox del PAC sigue en `SandboxFiscalFixtures` y 4 tests fiscales (Fase 6); el núcleo siembra `superadmin@test.com` y similares con
   contraseña = correo **también en producción** (entrega aparte antes de cualquier despliegue).

### 🟢 Well done
- `DatabaseSeederTiersTest` corre el seed completo bajo `production` y verifica que no nace ningún dato del cliente; `ClientDataInRepoTest` y `clientDataInRepo.test.ts` impiden la regresión.
- Salida escapada (`e()` en las líneas de pago, escape existente en el pie); sin tenant activo devuelve vacío en vez de fallar.
- Sin datos no se inventa nada: el bloque de pago desaparece y el estimador de layout recalcula la altura.
- Frontend: solo i18n muerto, mocks de tests y e2e; sin cambios de comportamiento.

## Next steps
Listo para merge, backend primero y frontend después. Sugerencia 1 a decidir por Luis.
