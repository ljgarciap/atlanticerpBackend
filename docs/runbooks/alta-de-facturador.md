# Alta de una persona que factura (Admin & Contabilidad)

Quien genera facturas desde "+ Crear Factura" necesita, además del acceso al módulo, dos permisos angostos por persona
(SCRUM-1117 y SCRUM-1140). Si falta alguno, la persona ve el bloqueo de la guarda pero no tiene cómo resolverlo.

| Permiso (`extra_permissions`) | Para qué sirve |
|---|---|
| `admin_contab.edit.receptor_fiscal` | Corregir RUC/DV y actualizar la razón social del cliente desde la vista previa. |
| `admin_contab.edit.receptor_consumidor_final` | Facturar a Consumidor Final cuando el documento no valida, o como decisión consciente. |

## Pasos

1. Confirmar con Luis quién pasa a facturar (decisión de negocio).
2. Un superadmin abre Seguridad > Usuarios, elige a la persona y agrega los dos permisos en "Permisos adicionales".
3. Pedir a la persona que cierre sesión y vuelva a entrar: los permisos viajan en el JWT. **Lo mismo vale al revés:** si más adelante se
   le quita un permiso, deja de surtir efecto hasta que su token expire o cierre sesión (igual que en el resto del sistema, `CheckPermission`).
4. Verificar en la vista previa de una factura de prueba: el aviso de la guarda debe mostrar "Facturar como consumidor final"
   y, si el documento no valida, el formulario de corrección.

## Aprobador y Contabilidad

Ya los tienen por `SpecialPermissionSeeder` (idempotente por fusión: corre en todos los entornos y nunca pisa permisos
existentes). Para una persona nueva NO se edita el seeder cada vez: la asigna un superadmin desde la pantalla. Solo si la
persona va a ser parte fija del equipo de facturación se agrega al seeder, para que sobreviva a recrear un entorno.

## Auditoría

- `admin_cont.invoice.receptor_consumidor_final`: cada factura emitida a Consumidor Final por decisión de quien factura
  (usuario, cliente, documento enmascarado y causa).
- `admin_cont.sub_client.razon_social_actualizada`: cada vez que "Actualizar cliente" cambia la razón social.
- `security.access_denied`: un intento de facturar a Consumidor Final sin el permiso.
