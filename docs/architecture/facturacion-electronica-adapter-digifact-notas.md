# Notas del adapter Digifact directo — a confirmar antes de Senior Review

Prep sin ticket, ver `Illumination/atlanticerp/docs/architecture/facturacion-electronica-pac-diseno.md`
(vive un nivel arriba, fuera de este repo). El esquema NUC completo no fue verificado campo a
campo a mano — se extrajo por fetch automatizado de `/pa/nuc` y `/pa/nuc/json`. Lista de lo que
específicamente queda "a confirmar" en `DigifactPacAdapter`/`NucJsonMapper`:

1. **Nombre real del campo del token** — `DigifactTokenService` prueba `token`/`Token` en la
   respuesta de `/login/get_token`; si el campo real tiene otro nombre, falla con un error claro
   (`RuntimeException`), no silenciosamente.
2. **Shape de la respuesta exitosa de `/v2/transform/nuc_json`** — `toResult()` asume que el CUFE
   viene en `authNumber`/`AuthNumber` y el XML/PDF en `responseData1`/`ResponseData1`, inferido de
   los campos de la respuesta que sí se confirmaron en el fetch anterior (`taxID`/`name`,
   `receiverTaxID`/`receiverName`, `subtotal`/`taxes`/`discounts`/`totalAmount`) — no hay un
   ejemplo de response completo verificado.
3. **`normalizeEstado()`** — los 3 estados (`RECIBIDO_EXITOSAMENTE`/`EN_COLA_DE_ENVIO`/
   `ERROR_AL_ENVIAR`) están documentados como conceptos, pero no se confirmó en qué campo exacto
   de la respuesta vienen (`status`/`Status`/`evento` son los 3 nombres probados, con fallback a
   "pendiente" si la respuesta es 2xx sin CUFE — nunca se asume "certificado" sin evidencia clara).
4. **`GetInfoRuc`** — nombres de parámetro/response (`taxId`, `valido`/`Valid`, etc.) sin
   confirmar, mismo criterio: fallback conservador, nunca un default que aparente éxito.
5. **`SHAREDINFO`** — parámetros de consulta (`serial`/`number` vs. CUFE) sin confirmar.
6. **QR** — el NUC no expone un campo de QR directo en lo relevado; `toResult()` lo deja `null` a
   propósito. Probablemente haya que armar la representación gráfica desde `GetDocument` (HTML/PDF)
   — no implementado en esta pasada.
7. **`Seller.BranchInfo.Code`** (sucursal) — sin equivalente en `AdminContFiscalSettings` hoy, se
   dejó como `config('services.digifact.branch_code')` (env var), no bloqueante para esta pasada.
8. **Códigos de forma de pago (`Payments[].Type`)** — solo 6 valores confirmados con más o menos
   certeza (`01` crédito · `02` efectivo · `03` tarjeta crédito · `04` tarjeta débito · `08`
   transferencia · `09` cheque); cualquier `metodo_pago` interno sin mapeo cae a `99` (otro).
   **Confirmado que NO coincide con la tabla de MSL** — nunca compartir el mismo mapeo entre
   adapters.

**Antes de que esto llegue a Senior Review:** confirmar el esquema real contra
`soporte@digifact.com.pa` o la documentación verificada, y reemplazar los fallbacks de esta lista
uno por uno — el diseño (`NucJsonMapper` con un método por sección) ya deja cada corrección
acotada a un método, sin tocar el resto del adapter.

## Validado en esta pasada
- PHPStan Level 8 limpio sobre `app/Modules/AdminCont/FacturacionElectronica` (corrida local,
  `vendor/bin/phpstan`, sin Docker — el stack compartido de `infra/` está en uso por el otro
  adapter corriendo en paralelo, ver nota de infraestructura abajo).
- 19/19 tests (PHPUnit local, `vendor/bin/phpunit`): 14 de `NucJsonMapperTest` (puro, sin
  Laravel/DB/red), 2 de `DigifactTokenServiceTest` y 3 de `DigifactPacAdapterTest` (Laravel
  `Tests\TestCase` sin DB, `Http::fake()`, más un Redis local descartable en el puerto 16380 solo
  para ejercitar `CircuitBreaker` — nunca el Redis del stack compartido).
- Ninguna llamada de red real a ningún dominio de Digifact en ningún momento de esta pasada.

## Nota de infraestructura (para quien retome esto)
`infra/test.sh`/`docker compose -f infra/docker-compose.yml` está pensado para UN checkout — con
2+ worktrees corriendo en paralelo sobre el mismo nombre de proyecto de Docker Compose, `exec`
puede pegarle al contenedor de otro worktree (los volúmenes son bind mounts relativos, pero el
nombre de proyecto por default colisiona: la primera corrida de `infra/test.sh` en este worktree
dio "No tests executed" porque el contenedor reusado no era el de este checkout). Esta pasada
evitó el problema corriendo `composer install` local en el worktree y usando el
PHP/PHPUnit/PHPStan del host directo — válido para tests que no tocan Postgres, pero **no sirve**
para nada que necesite `migrate:fresh`/tenant real. Confirmar con DevOps si vale la pena un
`-p <nombre-worktree>` fijo en `infra/test.sh` para que esto deje de ser un problema en despachos
paralelos futuros — mismo hallazgo que ya está en memoria del proyecto
(`feedback_shared_docker_stack_across_worktrees`), esta vez con la causa raíz exacta confirmada
(colisión de nombre de proyecto de Compose, no solo de `.env`/`vendor`).
