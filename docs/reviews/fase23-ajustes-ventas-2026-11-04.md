# Revisión — Fase 23, ajustes de Ventas & Diseño (2026-11-04)

Revisión propia en el hilo (no un agente independiente). Spec: `docs/specs/fase23-ajustes-ventas.md`.

**Veredicto: aprobada, 0 bloqueantes.**

## Lo revisado
- **Permisos (#9, #10):** las tres claves se escriben solo en el nivel 9. `VentasFase23Test` comprueba que **ningún otro nivel** las contiene y que el rol `management` apunta al nivel 9 (regla de fuga de la Fase 2). Los tramos pasan a `reports.configure`; el superadmin sigue entrando (bypass de `superadmin.all`, verificado contra la API local).
- **`team.scope` (#8):** resuelve al usuario por el JWT y usa `canViewTeamV2('ventas_diseno')`, el mismo criterio que los servicios; no cambia lo que ve cada rol, solo evita que el resultado «propio» se lea como «equipo». Pipeline y Pedidos quedan fuera a propósito (alcance dado también por otros permisos; Pedidos manda `team` por defecto).
- **Ficha (#7):** borrado en dos pasos (fila primero, archivo después): si falla el archivo, queda un huérfano en el log, nunca una fila que apunte a la nada.
- **Contactos (#2):** el conteo se hace antes del borrado; sin carrera relevante (el alta exige uno y la UI no ofrece borrar).

## Verificación
Backend: `VentasFase23Test` 5/5, PHPStan 0 errores, Pint limpio en los archivos nuevos (los modificados ya traían ruido preexistente). Frontend: tsc limpio, 250 archivos / 2945 tests. e2e `ventas-diseno.spec.ts` 11/11 contra el stack local.

## Sugerencias abiertas (no bloquean)
1. Los contactos del subcliente no se pueden «reemplazar» en una sola operación: para cambiar al único contacto hay que agregar el nuevo y luego borrar el viejo.
2. «Quitar ficha» no guarda historial de versiones (la auditoría registra el cambio de columnas del producto, no el archivo).
3. El 403 de `scope=team` aplica a las rutas listadas; si se agregan pantallas nuevas con alcance Mías/Equipo, hay que añadirles `team.scope`.
4. En un entorno ya sembrado, Gerencia recibe los permisos al migrar; otros roles que necesiten pagar comisiones se conceden por rol/usuario en Ajustes.
