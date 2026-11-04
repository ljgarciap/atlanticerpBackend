# Fase 24 — Validación previa a producción (D)

**Estado:** en borrador.

## 1. Principio
Atlantic es genérico: **ningún proveedor fiscal ni de correo es obligatorio**. Digifact (y MSL) son paquetes opcionales de Panamá que un tenant puede elegir; puede que nunca se use ninguno. Por eso el cableado queda listo **sin datos**: sin proveedor o sin credenciales no hay nada que validar y eso **no es un error ni un bloqueante**. La validación real solo aplica a quien elija un proveedor.

## 2. Entregable
- `php artisan fiscal:check-provider [--tenant=atlantic] [--ruc=…] [--allow-production]`: lee el proveedor configurado del tenant (el que sea) y, **solo si hay credenciales y se pasa `--ruc`**, consulta ese receptor por `PacProviderContract`. Sin proveedor («none»/apagado) o sin credenciales termina bien diciendo que no hay nada que validar. Solo lectura: no certifica ni emite; con ambiente de producción exige `--allow-production`; no imprime secretos.
- Tests (`FiscalCheckProviderCommandTest`): sin proveedor, sin credenciales, con credenciales sin RUC, consulta con respuestas simuladas, producción bloqueada.
- Correo: `php artisan mail:test {destino}` ya existe (el SMTP es configuración del entorno, no del producto).

## 3. Cuando un tenant elija un proveedor (no antes)
1. Credenciales de pruebas en el `.env` del entorno (nunca en git).
2. `fiscal:check-provider --ruc=<RUC de prueba>`; si la respuesta real no coincide con lo que el adaptador espera, ajustar con su regresión.
3. Certificar una factura y una nota de crédito en ambiente de pruebas y revisar `fe_transmisiones`; `DigifactPacAdapter::normalizeEstado()` es lo menos confirmado.
4. Correo: SMTP de prueba → `mail:test`, enlace de activación de cuenta y envío de una factura.

## 4. Fuera de alcance
Certificar en producción (emite documentos reales; aprobación individual) y el adaptador de la DIAN.

## 5. Aceptación
El comando es neutral al proveedor, no falla ni llama a la red cuando no hay proveedor o credenciales, y nunca expone secretos.
