# Pre-QA — SCRUM-997 ("Buscar Sustituto" sin texto: tope de candidatos con preselección en SQL)

Fecha: 2026-09-29 · Entorno: dev.atlanticerp.ai · Backend origin/dev **7326a38** (verificado) · Catálogo real: 25.816 productos del proveedor Lafayette.
Spec y mediciones por variante: `docs/specs/scrum997-substitute-candidates-cap.md`.

## Resultados
| Criterio | Resultado |
|---|---|
| Paridad con el ranking completo | 23 originales: recall@10 y @20 = 100 % (mín. 100 %), recall@50 = 99,9 % (mín. 98 %). Endpoint vs oráculo, 28 originales: mismos top-20 y mismas listas de puntajes; 16/28 difieren solo en el orden de empates exactos |
| Memoria / respuesta | 5,7 MB de pico (antes 146 MB); respuesta 195 KB (antes ~2,6 MB); ≤ 501 modelos hidratados (test) |
| Latencia HTTP (n = 100, caliente) | p50 335 ms, **p95 476 ms**, p99 754 ms, máx. 1.555 ms; otras corridas 419 / 583 / 762 ms con la máquina cargada. **Límite del criterio 1 (< 500 ms p95): ajustado, ver observaciones** |
| Permisos y forma | 401 sin token; 403 sin permiso (4 usuarios); vendedor no ve `cost` pero sí `similarity_percent`; original excluido; umbral ≥ 25 % |
| Entradas hostiles | sin 5xx; original inactivo OK; proveedor sin productos → `[]`; sin `provider_id` (29,6 K) 200 en 334 ms |
| Concurrencia | 40 peticiones / 20 hilos, todas 200 (p50 ~6 s por la cola de FPM) |
| UI (Playwright, `fefi@`) | `/compras/sustitutos`: selección del original y método "Buscar en catálogo" cargan sin 5xx. La UI siempre envía `search`; el camino sin texto solo se alcanza por API |

## Hallazgos
1. **MEDIO — corregido:** la preselección solo por precio/margen (pool 4.000) perdió el candidato puesto 4 del ranking completo (nombre casi idéntico) para el original 691. Se agregó el generador por texto (trigram, 1.000) y el recall volvió a 100 %; test de regresión incluido.
2. **BAJO — fuera de alcance, ticket aparte:** `SubstituteSimilarityService::closenessScore()` con margen original negativo produce `similarity_percent` > 100 (117,3 % medido) y premia candidatos lejanos. La preselección replica la fórmula exacta. Es regla de negocio (SCRUM-246, Daniela).

## Observaciones
- p95 de 476 ms está dentro del criterio pero sin holgura (5 % de peticiones > 500 ms; con la máquina cargada 419-762 ms). Palancas si se quiere margen: bajar `MAX_CANDIDATES` (~300) o el costo de hidratar/rankear en PHP. La latencia en EC2 dedicado no se midió bajo carga externa.

## Veredicto
Sin hallazgos abiertos del alcance del ticket. Pendiente de decisión de Luis: aceptar la latencia ajustada o reducir el tope.
