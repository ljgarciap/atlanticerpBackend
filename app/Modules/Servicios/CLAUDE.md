# Módulo Servicios — schema `servicios`

> Movido desde `atlanticerp/CLAUDE.md` (`/doctor` 2026-09-07, migración a carga perezosa — este archivo
> solo carga cuando se trabaja bajo `app/Modules/Servicios/`). Ver también `atlanticerp/CLAUDE.md` para
> convenciones globales del proyecto y `docs/architecture/servicios-fase4-diseno.md`/
> `servicios-fase4-batches.md` para el diseño completo de la Fase 4.

> Fase 4 (2026-08-02) — reemplaza por completo el modelo `installations` de abajo, que nunca se
> implementó (`App\Modules\Installations` solo tenía un `.gitkeep`). Diseño completo (87 REQ,
> 19 batches) en `docs/architecture/servicios-fase4-diseno.md` — acá solo lo YA implementado.
> **Batch 1 (implementado):** núcleo de Tickets. **Batch 3 parte 1 (2026-08-05):** cancelación.
> **Batch 3 parte 2 (2026-08-05):** formulario completo de Nuevo ticket — `sales_project_id`
> (obligatorio al implementarse; **opcional desde 2026-10-07**, ver abajo), `requerimientos_especiales` JSONB, tabla `ticket_products`. **Resto del Batch 4
> (2026-08-08):** columna `observaciones` + tabla `ticket_attachments`. **Batch 5 (2026-08-09):**
> `external_technicians` + `external_technician_rate_history` (Fase 2 — Equipo). **Batch 6
> (2026-08-09):** `internal_technicians` (Fase 2 — Equipo).

```sql
tickets(
  id, numero,                    -- PREFIJO-AÑO-NNNN, prefijo por tipo: INS/GAR/REC/RET
  tipo,                          -- installation | warranty | claim | retrofit
  subtipo,                       -- solo installation/warranty tienen subtipo, si no NULL
  tipo_instalacion,              -- internal | subcontracted
  descripcion,
  sales_project_id,              -- FK cross-schema → ventas_diseno.sales_projects, OPCIONAL al crear
                                  -- (2026-10-07, decisión de negocio aprobada por Luis: revierte REQ-246 RN6, que
                                  -- lo exigía desde Batch 3 parte 2). Sin proyecto el ticket queda sin `cliente`
                                  -- (se deriva del proyecto): el resto del código ya tolera `null` (`salesProject?->`)
  cliente, contacto, telefono, email, direccion,   -- snapshot al crear; `cliente` se deriva
                                  -- server-side del Subcliente del proyecto elegido (RN1 — nunca
                                  -- texto libre del cliente HTTP)
  requerimientos_especiales,     -- JSONB {catalog: string[], otros: string[]} desde Batch 3 parte
                                  -- 2 (antes texto libre) — claves válidas en Ticket::REQUIREMENT_LABELS
  observaciones,                 -- REQ-248, Batch 4 resto — texto libre opcional, distinto de descripcion
  estado,                        -- reported | scheduled | on_site | resolved | closed | cancelled
  cancellation_reason,           -- REQ-227 RN6, obligatorio al cancelar (Batch 3 parte 1)
  quote_status,                  -- not_applicable|pending|draft|sent|approved|rejected (denormalizado,
                                  -- ver docblock de la migración — Cotización real es Batch 11-12)
  inspection_report_status,      -- not_applicable|pending|completed (denormalizado, Informe real
                                  -- es Batch 8-9)
  internal_technician_id,        -- FK "suelta" sin constraint — internal_technicians es Batch 6
  scheduled_at, scheduled_ends_at,
  created_by, created_at, updated_at
)
-- Gate REQ-218: un ticket solo pasa a resolved/closed si quote_status ∈ {approved,not_applicable}
-- Y inspection_report_status ∈ {completed,not_applicable}. cancelled nunca pasa por este gate.
-- Cambio de estado exclusivo de lider_servicios/superadmin (routes/servicios.php). Crear un
-- ticket nuevo es más abierto (REQ-245 RN4): superadmin/lider_servicios/management/
-- vendedor, no solo lider_servicios/superadmin.

ticket_products(                 -- REQ-247, Batch 3 parte 2 — productos reclamados/afectados
  id, ticket_id, catalog_product_id,   -- FK cross-schema → ventas_diseno.catalog_products, nullable
  marca, referencia, descripcion,      -- snapshot al agregar
  cantidad_reclamo, cantidad_recibida, cantidad_pendiente   -- solo cantidad_reclamo se captura al
                                        -- crear (RN5); recibida/pendiente avanzan con el ticket
)

ticket_attachments(              -- REQ-248, Batch 4 resto — fotos/videos/archivos de referencia
  id, ticket_id, nombre_archivo, storage_key, size_bytes, mime_type,
  uploaded_by, created_at
)
-- Siempre privado (S3, presigned URL 15 min), sin versionado. Límites de cantidad/tamaño
-- configurables vía ServiciosSettingsService, nunca hardcodeados.

ticket_folio_counters(prefijo, year, last_number)   -- PK compuesta (prefijo, year)

external_technicians(            -- REQ-263→266, Batch 5 — subcontratistas
  id, nombre, empresa, especialidad, telefono, email,
  tarifa_dia, tarifa_visible,    -- ocultar es puramente visual (RN3), nunca afecta el cálculo real
  estado,                        -- active | inactive
  created_at, updated_at
)

external_technician_rate_history(id, external_technician_id, tarifa_dia, changed_by, created_at)
-- Solo consulta, inmutable (RN2) — un punto por cada cambio real de tarifa, nunca por el toggle
-- mostrar/ocultar. REQ-267 ("proyectos/tickets asignados" de cada técnico) queda en 0/vacío a
-- propósito hasta que exista el ítem Subcontratado de Cotización (Batch 11-12).

internal_technicians(           -- Batch 6 — perfil de técnico interno
  id, user_id,                  -- NULLABLE: un perfil de alta (REQ-259) no siempre es una cuenta
                                 -- real (no exige correo único ni pasa por POST /api/users) —
                                 -- sin user_id nunca es seleccionable para agendar un ticket
  nombre, telefono, email,      -- snapshot propio, independiente de `users`
  especialidad,                 -- general | warranty
  color, working_hours,         -- working_hours nunca editable vía UI (fijo, para REQ-256)
  created_at, updated_at
)
-- Estado (Disponible/En ruta/Ocupado/Fuera) NUNCA se persiste — calculado en cada request desde
-- tickets.scheduled_at/scheduled_ends_at vs hora actual en America/Panama (TechnicianStatusService).
-- Los 4 técnicos reales (Tecnico Test, Tecnico Test, Agustín Rodríguez, Tecnico Garantias Test) están
-- linkeados vía user_id por InternalTechnicianSeeder (idempotente, todos los entornos).
```

> `vendedor` vive en el Nivel 6 ("Supervisor") por una reasignación deliberada — ver
> `docs/changelog.md` (sección Servicios, nota 2026-08-02) para el detalle.
