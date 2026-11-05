# Pendientes priorizados — lo que falta tras las Fases 11 a 26

Estado: **propuesta para decidir (2026-11-06)**. Reúne los candidatos que siguen abiertos en los catálogos de `docs/flujos/` (los grupos 1 a 5 de `candidatos-priorizados.md` ya se hicieron en las Fases 12 a 16, y el CRM, las Fases 19, 22 y 25) y las sugerencias abiertas de la Fase 23 y de la Fase 24. Nada de esto se implementa sin tu «adelante» por bloque.

**Cómo leerla.** Cada punto trae su origen entre paréntesis (archivo y número del catálogo) y un tamaño: **S** (horas), **M** (un día), **L** (varios días). **«Verificar»** = el catálogo no lo tacha, pero la historia de fases sugiere que ya se resolvió; se confirma contra el código antes de empezar, y si es así solo se tacha en el catálogo.
Los tipos son los del catálogo: **[E]** es un defecto o una reacción inesperada ya vista ejecutando; **[C]** es una decisión de criterio o de negocio.

## Fase 27 — Sesión y segundo factor (seguridad) · prioridad alta
Todo sale de `mfa-recuperacion-clave.md` y `transversales.md`; es lo único abierto que toca seguridad de cuentas.
1. **Desactivar el MFA no pide clave ni código**: una sesión robada basta para quitar el segundo factor (mfa 2). **S**
2. **Confirmar con la sesión equivocada gasta el código** (mfa 1): comprobar la pertenencia del reto antes de validar el código. **S**
3. **El tope por usuario bloquea también la reactivación** y el reinicio por admin no lo limpia (mfa 3). **S**
4. **Gerencia fija la política de MFA pero no puede reiniciar el de una persona** (mfa 6): decidir si lo hace Gerencia o solo superadmin. **S** [C]
5. **Texto del correo de activación** menciona «AtlanticERP CRM» (mfa 5). **S**
6. **Topes de «olvidé mi contraseña» por IP**: una oficina comparte 5 solicitudes cada 15 min (mfa 7). **S** [C]
7. **Niveles de seguridad con nombres repetidos** que ignoran el `level` pedido (transversales 3), **motivo de «Ver como usuario» libre** (5) y **rol propio sin ninguna visibilidad sin aviso** (transversales-administracion 7). **M**
8. **MFA solo por correo** (mfa 4): sin aplicación autenticadora ni códigos de respaldo, y el mismo buzón recupera la clave. Es lo más grande; hace falta decidir si se quiere. **L** [C]

## Fase 28 — Contabilidad: cartera, tesorería, comisiones y reportes · prioridad alta (dinero)
1. **Tramos sin validar**: se pueden solapar, repetir orden o dejar el hueco de 0,01 (cartera 1, 2); los límites mostrados no son los que se aplican (cierres 6, cartera 6). **M**
2. **Una factura pagada se cuenta en «91+ días»** y mezcla cantidades con y sin saldo (cartera 3). **S**
3. **Vencimiento y días de crédito**: el atraso se mide desde la fecha de la factura y el crédito es global (cartera 4, 5). **M** [C]
4. **Tesorería**: una asignación equivocada no se corrige (tesoreria 2); salida sin cuenta sin aviso ni pendiente (3); el listado no filtra por tipo, fecha ni «sin cuenta» (4); desactivar una cuenta con movimientos cambia el saldo histórico sin aviso (1); si Contabilidad falta nadie asigna (5). **M**
5. **Comisiones externas**: comisión sobre el total con impuesto (comisiones 2, [C] a confirmar), totales del arquitecto en 0 sin régimen (1), nombre engañoso del estado (3), pago sin cuenta de pago sin aviso (4), archivos sin validar contra el monto (5). **M**
6. **Reportes**: dos reportes con el mismo total difieren en base e impuesto por 0,05 (reportes 1, causa sin investigar; sospechan de las notas de crédito) — **empezar por investigarlo**; tipo desconocido ignorado en silencio (2); sin rango propio en las series (4); reporte por cliente (3). **M**
7. **Aprobador compartido por caja chica, arqueos y anulaciones** (cierres 8) y umbral compartido entre notas de crédito y compras (notas 5): separar los umbrales. **M** [C]
8. **Verificar**: cierres 1, 2, 3, 4, 5 y 7, y notas 1 a 4 y 6, que las Fases 13 a 15 resolvieron.

## Fase 29 — Bodega y Compras · prioridad media
1. **Un aprobador para todo el inventario** y Gerencia recibe 403 aunque tiene el permiso (bodega-inventario 1); estado «aplicado» del conteo (2); rechazar pide motivo en Bodega y no en Compras (5). **M** [C]
2. **Ubicaciones, rutas y Kardex**: sin permiso angosto para escribir ubicaciones (ubicaciones 1); la ruta del día oculta lo atrasado, que es lo que más importa (2); sin orden por repartidor/hora (3); ubicaciones desactivadas se acumulan (4); Kardex sin filtro por fecha ni producto exacto (5); el Líder de Compras ve todo Bodega: dejarlo documentado como decisión (6). **M**
3. **Asistentes de llegadas**: el mapeo acepta cualquier usuario, no se puede quitar, cambiarlo no reasigna lo pendiente y el detalle no muestra al asistente (asistentes 3 a 6). **M**
4. **Compras**: estados que confunden — «por_aprobar» tras aprobar y «rechazada» que genera la orden (ordenes 5, 6). **S** [E]
5. **Reposición y proveedores**: agencias con nombre repetido, contacto principal no editable, `PUT` completo que se pisa entre dos personas, sin baja de proveedores/agencias, sin cantidad mínima ni redondeo a la presentación (reposición 1 a 6). **M**
6. **Familias**: sin editar, renombrar ni desactivar; moneda y modalidad fijas; existencias sin distinguir almacén (familias 4 a 6). **M**
7. **Fase 24, abiertos**: reemplazo de un documento de la orden en una sola operación y historial de envío que cubra más campos que contenedor y transportista. **M**

## Fase 30 — Servicios · prioridad media
1. **Tickets**: `internal_technician_id` recibe un id de usuario (tickets 3); subtipos de sistema con significado en el código (4); instalaciones sin compuerta de cierre, el SLA no bloquea nada (5). **M**
2. **Informe de inspección**: el consumo de la Reserva solo se aplica al primer guardado, de modo que corregir un material no ajusta el Kardex (informe 1); guardar de nuevo como formulario borra el archivo y al revés (2); fotos sin borrar ni reemplazar (4); sin borrador (5); formularios por tipo fijos en el código (6). **L**
3. **Hoja de reclamo**: no condiciona el cierre (reclamo 1); una hoja completada no se corrige, falta reapertura con motivo (2); sin borrador (3); quién ve la carga por técnico y la comisión (4, 7) [C]; `completados-anio` cuenta resueltos y cerrados (5); tipo de documento desconocido devuelve lista vacía (6). **M**
4. **Técnicos e insumos**: insumo nuevo siempre «bajo mínimo» (técnicos 1); insumos y herramientas sin editar ni dar de baja (2, 7); la ficha del técnico no se liga a un usuario (3); `PUT` completo borra lo no reenviado (4); recibir una herramienta sin compra real (5); Gerencia no puede corregir lo que ve (6). **M**

## Fase 31 — Ventas & Diseño y administración · prioridad media-baja
1. **Fase 23, abiertos**: reemplazar el contacto único de un subcliente en una sola operación; historial de versiones de la ficha técnica («quitar ficha»); comprobar que toda pantalla nueva con alcance Mías/Equipo lleve la guarda `team.scope` (hoy se acuerda a mano; conviene una prueba estructural como la de los ids no numéricos); y documentar que, en un entorno ya sembrado, otros roles que paguen comisiones se conceden por rol/usuario. **M**
2. **Ventas**: sin baja ni corrección en bloque de clientes y arquitectos (ventas 3); el detalle del arquitecto ignora a los asociados (5); Calendario depende por completo de Outlook (11); «Aprobaciones pendientes» del Inicio siempre vacía (12). **M**
3. **Cotización con dos estados** (`status` y `document_status`) (cadena 1). **M** [C]
4. **Administración**: el perfil de empresa es del aprobador primario y no de Gerencia (administración 1); departamentos sin borrar ni reasignar (2); reglas de notificación solo para CRM, no para Compras, Bodega ni Servicios (5); ajustes globales solo enteros y dispersos (6); reglas de aprobación que se solapan se avisan pero no se impiden (4). **L**
5. **Verificar**: cadena 2 a 9 y 12, y transversales 1 y 2 (Fases 12 a 16).

## Decisiones de fondo (no son una fase)
- **Hosting real y dominio** (Fase 10 en pausa): hacen falta dominio, presupuesto mensual y una cuenta con credenciales de mínimo privilegio. Nada de AWS se ejecuta sin aprobación individual.
- **Correo real en producción**: el enlace de activación y el MFA lo necesitan; hoy `MAIL_MAILER=log`.
- **Validar `digifact` con credenciales reales** antes de producción (solo probado con respuestas simuladas) y **adaptador de la DIAN** (Colombia), fuera de alcance salvo que lo pidas.
- **Aplanar migraciones históricas**: opcional; no recomendado ahora. **Pint sobre todo el repo**: descartado.
- **Sin ejercitar en navegador**: exportar el PDF del catálogo y crear un producto completo; los e2e históricos de binax siguen archivados en `e2e-archivo/`.

## Orden que propongo
**27 (seguridad) → 28 (dinero) → 29 → 30 → 31**, con una pasada de «verificar» (media jornada) antes de la 28 para tachar lo ya resuelto en los catálogos y no planificar de más. Cada fase sería una spec con decisiones en lenguaje llano, como en la Fase 24, y un PR por repositorio.
