# Senior Review — SCRUM-928 (D-6): `AdminContBankAccountSeeder` (cuentas bancarias sembradas en todos los entornos)

**Fecha:** 2026-09-18
**Revisor:** Senior Reviewer (revisión independiente y adversarial, solo lectura)
**Alcance:** árbol sin commitear en `atlanticerp-backend`, rama `dev` (0 commits sobre `origin/dev`), SOLO:
`database/seeders/AdminContBankAccountSeeder.php` (nuevo), `tests/Feature/AdminCont/AdminContBankAccountSeederTest.php`
(nuevo), `database/seeders/DatabaseSeeder.php` (modificado), eliminados `AdminContBankAccountNumberSeeder.php`,
`AdminContBankAccountNumberSeederTest.php` y `config/admin_contab.php`, `.env.example` (bloque
`ADMIN_CONTAB_BANK_ACCOUNT_NUMBER` quitado) y el texto sobre la cuenta bancaria en
`docs/specs/scrum-928-935-correcciones-uat-admincont-analista-spec.md` y `docs/architecture/scrum-928-935-batches.md`.
Fuera de alcance (no revisado): todo lo de facturación B1b/B2 que está mezclado en el mismo árbol.
Vara de medir: D-6 de la spec de SCRUM-928, pedido de Luis del 2026-09-18 (cuentas en un seeder, no en env, mismo
criterio que `AdminContFiscalIdentitySeeder`) y el estado real de `test`/`dev`/`prod` informado en el encargo.

## Veredicto: DEVUELTO (0 CRÍTICO, 1 ALTO bloqueante, 2 MEDIO, 4 BAJO)

La lógica del seeder para producción es sólida (no pisa, no reactiva, no duplica en los casos previstos, orden
correcto, datos correctos). Lo devuelve un problema de integración que el propio diseño no anticipó: al vivir en
`DatabaseSeeder`, el seeder también corre en la base de PHPUnit y **rompe 7 tests preexistentes**. Sin resolverlo el
gate de CI (PHPUnit) quedaría en rojo. El arreglo es de una línea y no toca la lógica del seeder.

## Verificación ejecutada por este revisor

- `infra/test.sh --filter=AdminCont`: 592 tests, 2795 assertions, **7 fallos** (ver ALTO-1). Para obtener la lista
  limpia se corrió después, de forma secuencial (nunca concurrente), un segundo `infra/test.sh` con
  `--filter='AdminContPaymentControllerTest|AdminContAccountStatementControllerTest|AdminContInvoiceControllerTest|AdminContBankAccountControllerTest|AdminContBankAccountSeederTest'`:
  151 tests, 7 fallos, los mismos. Los 9 tests de `AdminContBankAccountSeederTest` pasan.
- PHPStan Level 8, proyecto completo (`phpstan.neon` analiza solo `app/`): `[OK] No errors`. Eso NO cubre seeders ni
  tests; se corrió aparte sobre el seeder: 1 mensaje `nullsafe.neverNull` (`$this->command?->warn`, línea 84,
  cosmético, el seeder eliminado usaba el mismo patrón); sobre el test: 15 mensajes `property.nonObject` por
  `->fresh()->campo`, patrón común en toda la suite y fuera del alcance de PHPStan del proyecto. Ninguno bloquea.
- Lectura de `Illuminate\Database\Seeder::__invoke` y `WithoutModelEvents` en `vendor/` para el punto de auditoría.
- Búsqueda de referencias residuales (ver sección "Revisado sin hallazgo").
- Comparación de datos contra el estado de `test` del encargo (no se consultó ningún entorno: sin ssh, solo lo
  declarado en el encargo).

## Hallazgos

### ALTO-1 (bloqueante) — Sembrar en `DatabaseSeeder` deja 4 cuentas en la base de PHPUnit y rompe 7 tests existentes (VERIFICADO)

`database/seeders/DatabaseSeeder.php:32` llama al seeder sin condición de entorno, y `FeatureTestCase` corre
`DatabaseSeeder` tras cada `migrate:fresh` (`protected string $seeder = DatabaseSeeder::class`). Resultado: toda la
suite arranca con Banco General 4554 (activa, con número), Banistmo tarjeta 9034 (activa) y 2 inactivas, ids 1-4.
Antes de este cambio el seeder de número era un no-op sin la variable de entorno y la base de tests no tenía ninguna
cuenta. Fallan, sin haber sido modificados por este trabajo (no aparecen en `git status`, salvo el punto 4-5):

1. `AdminContAccountStatementControllerTest::test_bloque_de_pago_siempre_presente_y_consistente_con_facturacion`
   (línea 273): esperaba `4521`, recibe `Banco General 03-01-01-074455-4`.
2. `AdminContAccountStatementControllerTest::test_928_bloque_de_pago_no_enmascara_si_la_cuenta_no_tiene_numero_completo`
   (línea 316): esperaba `null`, recibe `03-01-01-074455-4`.
3. `AdminContAccountStatementControllerTest::test_928_numero_completo_en_blanco_cuenta_como_sin_numero` (línea 332): ídem.
4. `AdminContInvoiceControllerTest::test_928_pdf_de_factura_no_enmascara_la_cuenta_sin_numero_completo` (línea 864).
5. `AdminContInvoiceControllerTest::test_928_pdf_de_factura_muestra_la_cuenta_completa_cuando_esta_cargada` (línea
   887): esperaba `...074554-4`, recibe `...074455-4`. (Este archivo es de B1b y está fuera de alcance; el fallo es
   consecuencia directa de este seeder, no de ese trabajo.)
6. `AdminContPaymentControllerTest::test_default_bank_account_tarjeta_usa_banistmo_tarjeta_credito` (línea 199):
   `defaultBankAccountFor()` devuelve la Banistmo sembrada (id 2), no la del test.
7. `AdminContPaymentControllerTest::test_default_bank_account_efectivo_y_transferencia_usan_banco_general_corriente`
   (línea 210): ídem con la Banco General sembrada (id 1).

Causa común: cada test crea "su" cuenta con `create()` y asume que es la única (`paymentBlock()` usa la activa más
antigua; `defaultBankAccountFor()` usa `->first()` por banco+tipo). Con las cuentas sembradas, la "más antigua" es
la sembrada. El mismo mecanismo pudo dar falsos verdes en otros tests que hoy pasan por casualidad (por ejemplo los
de `AdminContBankAccountControllerTest` que cuentan o listan cuentas).
El autor ya lo intuyó (el comentario de `setUp()` del test nuevo dice "La base de tests no es fresca por test") y lo
resolvió solo dentro de su propio test, borrando por terminación; el resto de la suite no tiene ese blindaje.
Cómo verificarlo: `infra/test.sh --filter=AdminContPaymentControllerTest` (2 fallos) y
`--filter=AdminContAccountStatementControllerTest` (3 fallos).
Arreglo recomendado (no lo apliqué, solo lectura): en `DatabaseSeeder`, llamar a `AdminContBankAccountSeeder` solo
`if (! app()->environment('testing'))` (precedente: `CatalogUatTestDataSeeder` se salta `testing` por la misma
razón). El test propio invoca la clase directamente (`(new AdminContBankAccountSeeder)->run()`), así que sigue
ejerciendo el seeder; su `setUp()` que borra por terminación queda inofensivo pero innecesario. Alternativa peor:
adaptar los 7 tests + auditar todos los que asumen "sin cuentas". Tras el arreglo hay que volver a correr
`infra/test.sh --filter=AdminCont` y el gate completo (suite completa) antes de push, como exige el CLAUDE.md del
proyecto: el encargo indica que el diseño se verificó con 3 mutaciones y pruebas dirigidas, pero este fallo solo
aparece al correr el módulo entero.

### MEDIO-2 — La llave de coincidencia usa 3 campos editables desde la UI; una edición legítima provoca una cuenta duplicada activa en el deploy siguiente (VERIFICADO en código; el escenario en prod NO ejercido)

`AdminContBankAccountSeeder.php:93-98` identifica "la misma cuenta" por `lower(banco)` + `tipo_cuenta` + `ultimos_4_digitos`.
`UpdateBankAccountRequest.php:29-40` permite editar los tres (`sometimes`), y no existe endpoint para borrar
(`routes/admin-contab.php:66-73`: solo index/store/update/deactivate/reactivate). Escenario realista: tras el primer
deploy en producción Contabilidad/Gerencia1 corrigen "Banco General" → "Banco General S.A.", o cambian el tipo, o el banco
con trailing space; el siguiente `db:seed --force` (corre en cada deploy) no encuentra la cuenta y **crea otra
activa** con el nombre/tipo/terminación originales. Efectos: (a) aparece duplicada en los selectores de Cobros y
Comisiones sin forma de eliminarla (solo desactivar); (b) `PaymentService::defaultBankAccountFor()`
(`PaymentService.php:164-176`) usa `->first()` sin `orderBy` sobre `banco = 'Banco General'` exacto, así que con dos
candidatas el resultado es indeterminado; (c) para las dos inactivas el efecto es solo ruido. Lo mismo aplica a
las cuentas que hoy están en `test`/`dev` si su `banco`/tipo/terminación difieren en algo (espacios, casing ya
tolerado) del array: el primer deploy las duplicaría. El encargo indica que coinciden, pero no se pudo verificar
sin acceso a los entornos.
Cómo verificarlo: en local, sembrar, editar `banco` de la cuenta id 1 vía `PUT /api/admin-contab/bank-accounts/1`,
volver a ejecutar el seeder y contar filas con `ultimos_4_digitos = '4554'` (2).
Sugerencias, de menor a mayor cambio: (1) antes del primer deploy a cada entorno, correr una consulta de solo
lectura que compare `(lower(trim(banco)), tipo_cuenta, ultimos_4_digitos)` de las cuentas reales contra `ACCOUNTS`;
(2) endurecer la llave: `lower(trim(banco))` y, para RELLENAR el número, coincidir solo por `ultimos_4_digitos` +
banco normalizado (sin `tipo_cuenta`); (3) solo CREAR cuentas cuando `bank_accounts` está vacía (el caso de prod)
y en el resto de entornos limitarse a rellenar el número: elimina de raíz el "resucitar/duplicar" por drift, a
costa de que un entorno nuevo con una sola cuenta manual no reciba las otras 3 (aceptable).

### MEDIO-3 — Falta un test para el predicado `ultimos_4_digitos` de la llave: quitarlo sobrevive (VERIFICADO por análisis de los datos de los tests; mutación no ejecutada)

Las 3 mutaciones ya hechas (quitar `lower(banco)`, quitar la guarda de no-pisar, invertir el orden) sí fallan. Pero
ningún test crea una cuenta con el MISMO banco y tipo que una definición y DISTINTA terminación (por ejemplo
Banco General corriente `1111`): `test_misma_terminacion_pero_otro_banco_o_tipo...` varía banco o tipo manteniendo
`4554`. Si alguien quita `->where('ultimos_4_digitos', ...)` (línea 96) todos los tests siguen en verde, y el
seeder estamparía el número de `03-01-01-074455-4` en cualquier cuenta "Banco General corriente" preexistente, o
sea imprimiría un número de cuenta ajeno en Facturas y Estados de Cuenta enviados a clientes. Es exactamente la
invariante de seguridad más cara del seeder y es la única sin red.
Agregar: cuenta `Banco General / corriente / 1111` sin número → tras correr el seeder su número sigue `null` y existe
una fila `4554` nueva con el número. Análogo para la ausencia de `tipo_cuenta` (ya cubierto por `$otroTipo`).
Sin cobertura además: tenant inexistente (rama `warn` + return) y dos filas que ya coinciden (el `oldest('id')`
no se verifica).

### BAJO-4 — Sin índice único ni bloqueo: dos deploys simultáneos al mismo entorno pueden duplicar (SOSPECHADO, riesgo bajo)

`bank_accounts` no tiene índice único (`2026_08_19_100017_create_admin_contab_bank_accounts_table.php`) y el
`first()` → `create()` del seeder no es atómico. Requiere dos `db:seed --force` sobre la misma base a la vez
(`ci-backend.yml:291/395/501` y `infra/deploy-manual.sh:123` coexisten mientras el CI de GitHub falla por
facturación). Probabilidad baja, misma consecuencia que MEDIO-2. Mitigación barata si se quiere: envolver el bucle
en `DB::transaction` con `pg_advisory_xact_lock`. No bloquea.

### BAJO-5 — Alta/relleno de datos financieros sin rastro en `audit_logs`, y un vaciado deliberado se revierte en el siguiente deploy (VERIFICADO por lectura de Laravel)

`DatabaseSeeder` usa `WithoutModelEvents` y `Seeder::__invoke` envuelve todo `run()` en `Model::withoutEvents`
(incluidos los seeders hijos invocados con `call()`), por lo tanto `LogsAuditTrail` (`created`/`updated`) no
dispara: las cuentas creadas en prod y el número estampado no dejan fila en `audit_logs`. Consecuencia doble:
positivo, el número completo NO se copia en `new_values` en texto plano; negativo, en una épica de telemetría
anti-fraude (SCRUM-788, REQ-631→636) el alta de datos de cobro no queda trazada. Es coherente con el resto de los
seeders del proyecto y no es bloqueante. Relacionado: como "en blanco = sin número", si alguien vacía a propósito
el número de Banco General (D-6 admite "—"), el siguiente deploy lo vuelve a rellenar.

### BAJO-6 — `mb_strtolower` (PHP) vs `lower()` (Postgres) y espacios (latente)

Hoy los 4 bancos son ASCII, así que no difieren. Con un banco acentuado ("Banco Atlántico") y una base con
`LC_CTYPE=C`, `lower()` de Postgres no baja `Á` pero `mb_strtolower` sí: la coincidencia fallaría y se duplicaría.
Tampoco hay `trim`. Se resuelve junto con MEDIO-2 (normalizar en PHP y comparar en PHP, o `lower(trim(banco))` con
el mismo criterio de ambos lados). No se consultó la collation de `test`/`prod`.

### BAJO-7 — Fuente única del número de cuenta: ahora hay una copia más

No es un hallazgo de este cambio, pero conviene decirlo: el número ya está literal en `resources/views/ventas-diseno/quote-pdf.blade.php:238`,
`resources/views/servicios/service-quote-pdf.blade.php:137`, `QuotePdfLayoutEstimator.php:167`,
`ServiceQuotePdfLayoutEstimator.php:100`, los i18n `ventasDiseno.json` (es/en) y varios tests. Si la empresa
cambia de cuenta habrá que tocar todos esos sitios y el seeder. Deuda preexistente, no bloquea.

## Revisado sin hallazgo

- **Datos del array `ACCOUNTS` contra `test`:** Banco General | corriente | 4554 | activa | con número; Banistmo |
  tarjeta_credito | 9034 | activa; Bancolombia | ahorro | 4534 | inactiva; Davivienda | ahorro | 0032 | inactiva.
  Coincide 1:1 con las 4 filas de `test` del encargo (banco, tipo, terminación, actividad, orden de ids).
- **Número completo:** `03-01-01-074455-4` → dígitos `0301010744554`, últimos 4 = `4554` = terminación declarada
  (además lo cubre `test_el_numero_completo_declarado_termina_en_los_ultimos_4_declarados`); 13 dígitos + guiones
  caben en `numero_cuenta_completo` (`string`, sin límite duro a 50 en columna).
- **Constantes `TIPO_*`:** `TIPO_CORRIENTE`, `TIPO_AHORRO`, `TIPO_TARJETA_CREDITO` existen en `AdminContBankAccount`.
  `ultimos_4_digitos` (obligatoria, `string(4)`) va en todas; `moneda` `USD` (3 letras).
- **Orden / invariante de `paymentBlock()`:** Banco General primero → menor id activo → cuenta de la Factura y del
  Estado de Cuenta; el test lo verifica con las 4 sembradas (`test_con_las_cuentas_sembradas...`). Nota: solo vale
  cuando la tabla está vacía al sembrar (prod); en `test`/`dev` ya lo cumplen los ids existentes.
- **Casos de seguridad en prod recorridos:** casing distinto (tolerado por `lower(banco)`, cubierto por test);
  misma terminación otro banco/tipo (no se adopta, cubierto); cuenta desactivada a propósito (el match incluye
  inactivas, no se reactiva ni se crea otra, cubierto); número cargado desde pantalla (guarda `trim(...) === ''`, no
  pisa, cubierto); número en blanco (se rellena, cubierto); tenant ausente (`warn` + return, `?->` correcto porque
  `$this->command` es null fuera de Artisan; el seeder de identidad fiscal usa `->` y fallaría en ese caso, este
  es mejor); `finally { Tenant::forgetCurrent(); }` igual que el de identidad fiscal; FKs a `bank_accounts` son
  `nullOnDelete`, así que el `delete()` del `setUp` del test no puede fallar por movimientos/cobros huérfanos.
- **Referencias residuales:** `grep` de `AdminContBankAccountNumberSeeder`, `ADMIN_CONTAB_BANK_ACCOUNT_NUMBER`,
  `config('admin_contab`, `admin_contab.php`, `bank_account_number` en app/, config/, database/, tests/, routes/,
  infra/, docs/ (excepto docs/reviews y docs/pre-qa) y los workflows: solo quedan la mención histórica en el
  docblock del seeder nuevo y la nota de "se eliminó" en la spec (correctas). `.env.example` quedó sin bloque
  colgante. Ni `ci-backend.yml` ni `deploy-manual.sh` mencionan la variable (un `.env` de servidor que aún la tenga
  es inofensivo).
- **Documentación:** el texto de spec y de batches describe fielmente el seeder (4 cuentas, actividad, "nunca
  pisa/reactiva/duplica", orden, variable eliminada).
- **Convenciones:** sin umbrales de negocio hardcodeados (son datos maestros de la empresa que además tienen
  CRUD en Cuentas Bancarias; el seeder solo da el estado inicial, mismo criterio que `AdminContFiscalIdentitySeeder`);
  `declare(strict_types=1)`, namespace `Database\Seeders`, docblock que explica el porqué; nombres claros.
- **Efecto en producción a tener presente (intencional):** sembrar exactamente "Banco General"/corriente y
  "Banistmo"/tarjeta_credito activa en prod activa por primera vez la auto-selección de cuenta de
  `PaymentService::defaultBankAccountFor()` (REQ-466), que hoy devuelve `null` allí. Es el mismo comportamiento que
  ya tiene `test`.

## Preocupación de negocio dicha en voz alta: el número de cuenta real en el código fuente

Se revisó y es de bajo riesgo incremental. Es un dato de cobro que la empresa ya imprime en cada cotización y
factura que envía a clientes, y **ya está literal en el repo** desde antes (plantillas PDF de cotización, i18n del
frontend, tests, ver BAJO-7); este cambio no agrega exposición nueva. No es una credencial (no permite retirar
fondos). Lo que sí implica: queda para siempre en el historial de git del repo privado `Grupo-Lafayette/atlanticerp-backend`
y en los backups/clones, y facilita ingeniería social si alguien con acceso al repo lo usara para suplantar a la
empresa. Recomendación: que Luis lo acepte explícitamente (ya lo pidió así) y que el repo se mantenga privado con
acceso mínimo. El resto de las cuentas (sin número) no expone nada.

## Reglas cumplidas / a cumplir antes de QA

- Corregir ALTO-1 y volver a correr `infra/test.sh --filter=AdminCont` (esperado: 0 fallos) y, antes de cualquier
  push, el gate completo (suite backend completa + PHPStan), ya que un cambio en `DatabaseSeeder` afecta a toda la
  suite y no solo a AdminCont.
- MEDIO-2 y MEDIO-3 no bloquean el paso a Pre-QA por sí solos, pero MEDIO-3 es una línea de test y MEDIO-2 debería
  resolverse o aceptarse explícitamente por Luis antes del primer deploy a `atlanticerp.ai`, con la consulta de solo
  lectura previa a `test`/`dev`/`prod` como mínimo.
- Tras corregir, este revisor debe volver a revisar (ALTO devuelve al PM/Backend Dev).

---

## Re-revisión (2026-09-18, tras el DEVUELTO)

**Veredicto: APROBADO** (0 CRÍTICO, 0 ALTO, 0 MEDIO; 2 BAJO nuevos no bloqueantes, ver abajo).

### Verificación ejecutada
- `infra/test.sh --filter=AdminCont` (una sola corrida): **597 tests, 2818 assertions, OK** (antes: 592 con 7 fallos).
- PHPStan Level 8, proyecto completo: `[OK] No errors`.
- La suite backend completa (3525 OK) la corrió el Backend Dev; este revisor no la repitió.
- Leídos completos: `AdminContBankAccountSeeder.php`, `AdminContBankAccountSeederTest.php`, diff de `DatabaseSeeder.php`.

### ALTO-1 — RESUELTO
`DatabaseSeeder.php:35` envuelve la llamada en `if (! app()->environment('testing'))`. Comprobado que es el entorno
correcto: `.env.testing:6` y `ci-backend.yml:170` fijan `APP_ENV=testing`, y los entornos reales (`dev`, `qa`,
`production`) y `local` siguen sembrando. Los 3 archivos que fallaban vuelven a verde (dentro de la corrida AdminCont de
597 tests). El test propio sigue siendo válido: invoca `(new AdminContBankAccountSeeder)->run()` directamente (no
depende del guard) y su `setUp()` ahora borra TODAS las cuentas para partir de la tabla vacía de prod (las FK son
`nullOnDelete`); como en `testing` ya no hay cuentas sembradas, ese borrado es hoy inofensivo y defensivo. No hay otro
seeder que cree cuentas (`grep` en `database/seeders`), así que no queda otro camino que ensucie la base de tests. No
existe un test que verifique el guard en sí; lo protege la suite completa (si alguien lo quita, reaparecen los 7
fallos), suficiente.

### MEDIO-2 — RESUELTO
`AdminContBankAccountSeeder.php:101-118`: `$tablaVacia` se calcula una vez antes del bucle y solo entonces se crea; en un
ambiente con cuentas nunca se crea, solo se rellena el número de la cuenta reconocida. La comparación es
`lower(trim(banco))` contra `mb_strtolower(trim(...))` (cierra también BAJO-6 en lo que respecta a espacios).
Un renombrado o edición posterior ya no produce un duplicado activo (`test_una_cuenta_renombrada...` con 2 corridas).

**Sobre la llave estricta (tipo + terminación): de acuerdo.** El modo de falla de una llave estricta es "no se estampa
el número" (la Factura muestra "—", visible y corregible desde Cuentas Bancarias); el de una llave laxa sería imprimir
el número de Banco General en una cuenta ajena en documentos al cliente (invisible). La asimetría justifica estricta.

### MEDIO-3 — RESUELTO
Los tests nuevos cubren: Banco General corriente con terminación 1111 (`test_banco_general_corriente_con_otra_terminacion...`,
línea 197: es el que mata la mutación "quitar filtro de terminación"); varias coincidencias solo rellena la más antigua
(línea 209); cuenta renombrada sin duplicar ni recibir número (122); tabla con cuenta propia no crea nada (111);
normalización de espacios y mayúsculas (135); cuenta en blanco, no pisar, no reactivar, idempotencia. Razoné el resto de
mutaciones y todas caen en algún test: quitar el predicado de `tipo_cuenta` falla en `test_misma_terminacion_pero_otro_banco_o_tipo...`
(la cuenta ahorro 4554 recibiría el número); calcular `$tablaVacia` dentro del bucle falla en el test de las 4 cuentas
(quedaría 1); quitar `lower`/`trim` falla en los de casing/normalización. (No ejecuté yo las mutaciones; las que el
Backend Dev dice haber ejecutado son consistentes con este análisis.)

### Pregunta del coordinador: "crear solo si la tabla está vacía" ¿abre un caso peligroso?
No peligroso; sí una degradación silenciosa aceptable. Caso prod con una cuenta creada a mano antes de promover:
- No se siembran las otras 3 y el número solo se rellena si el nombre/tipo/terminación coinciden exactos. Es
  exactamente el estado que tiene prod hoy ("—" en la Factura y sin cuentas por defecto para Cobros), no un estado peor.
- Con esa cuenta manual como única activa, `paymentBlock()` usaría esa cuenta: visible en la Factura, no oculto.
- Si la cuenta manual es realmente Banco General corriente 4554, sí se le rellena el número (caso que el guard
  no impide, correcto).
- Consecuencia funcional adicional a tener presente: sin Banistmo tarjeta activa, `defaultBankAccountFor('tarjeta')`
  devuelve `null` (RN3: campo manual), ya previsto por su docblock.
Lo considero **aceptable sin bloquear**, pero recomiendo una advertencia en consola (ver BAJO-8) porque hoy el
operador del deploy no se entera de que el seeder "no hizo nada" en ese ambiente.

### Hallazgos nuevos (no bloqueantes)
- **BAJO-8 — silencio cuando el seeder no cumple su propósito** (`AdminContBankAccountSeeder.php:109-116`): cuando la
  tabla no está vacía y alguna definición no se reconoce (no hay `$existing`), el `continue` no deja rastro.
  Sugerencia: `$this->command?->warn("Cuenta bancaria {$account['banco']} {$account['ultimos_4_digitos']} no reconocida; no se crea ni se rellena.")`
  en esa rama (el patrón `?->` ya usado). La salida del `db:seed` queda en los logs del deploy y evita el "por qué
  la Factura de prod muestra —" a posteriori. Idealmente también un warn cuando se rellenó el número.
- **BAJO-9 — alta no atómica** (`AdminContBankAccountSeeder.php:101-118`): si `create()` fallara a mitad de las 4
  (error de BD), el siguiente `db:seed` ya vería la tabla "no vacía" y nunca completaría las que faltan. Improbable
  (el deploy falla y se reintenta con la tabla parcialmente poblada, y ahí ya no se completa). Mitigación opcional:
  crear las 4 dentro de `DB::transaction` (todo o nada). No bloquea.

### Aceptados por el Backend Dev (sin objeción de este revisor)
BAJO-4 (concurrencia, deploys manuales y secuenciales), BAJO-5 (sin auditoría, documentado en el docblock; el vaciado
deliberado del número se rellena en el siguiente deploy, también documentado), BAJO-6 (collation; los 4 bancos son ASCII,
y el `trim` ya está), BAJO-7 (número en otros sitios: informativo para Luis). Sigue en pie la nota de negocio: el número
de cuenta ya estaba en el repo (plantillas PDF, i18n, tests); Luis debe aceptar explícitamente que ahora también viva
en un seeder.

### Pendiente antes de push (regla del proyecto)
Gate completo backend (suite completa + PHPStan) sobre el árbol final: el Backend Dev reporta 3525 OK y PHPStan OK;
repetir sobre el commit definitivo si el árbol cambia (incluye trabajo B1b/B2 aún sin revisar por separado, fuera de
este alcance). Antes del primer deploy a `atlanticerp.ai`, confirmar por consulta de solo lectura que `bank_accounts` está
vacía en prod (supuesto del encargo, verificado el 2026-09-18 por el coordinador, no por este revisor).
