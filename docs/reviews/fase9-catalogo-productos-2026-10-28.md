# Senior Review — Fase 9 (catálogo de productos genérico, 9a + 9b + 9c)

**Veredicto**: aprobada, 0 bloqueantes. Spec: `docs/specs/fase9-catalogo-productos-generico.md`.

## Revisado
- **9a — categorías**: dominio `product_category` (`usage` = `catalog_products.category`). Las 3 validaciones y la búsqueda leen el catálogo; `CatalogProduct::CATEGORIES` y las listas/etiquetas del frontend desaparecen. La migración deriva las entradas de los DATOS (cada categoría ya en uso + `general` de sistema): ningún entorno con datos pierde una categoría y el código nuevo no lleva vocabulario del sector. La búsqueda del catálogo compara clave **o etiqueta** (el vocabulario ya es de la empresa). La foto de relleno UAT deriva el color de la clave.
- **9b — `dialux` → `requires_technical_study`**: renombre sin pérdida (columna, filtro de API, prompts de IA, textos); `down()` lo devuelve.
- **9c — ficha técnica**: dominio `technical_spec_field` con `usage_json` (nuevo en `CatalogService::inUse`: `jsonb_exists` sobre la columna JSONB, así un campo con datos no se borra). La migración siembra 5 campos genéricos de sistema y una entrada por cada clave ya presente en algún producto. PDF y modal imprimen los activos del catálogo en su orden y, después, cualquier clave con valor que el catálogo no conozca (no se oculta ningún dato); un campo desactivado se omite a propósito.
- **Contrato**: `PERMITIDOS`/`ALLOWED` quedan vacíos en ambos repos; `dialux`, las 8 claves/etiquetas de categoría y los campos de la ficha técnica de iluminación pasan a prohibidos.
- **Seguridad**: sin permiso nuevo (los catálogos usan `superadmin.all`); `usage_json` interpola solo el nombre de columna que viene de `config/catalogs.php` (no de entrada de usuario).

## Verificación
PHPStan 0. Backend: tests relacionados y nuevos en verde (categorías, estudio técnico, ficha técnica) y suite completa de la rama 9c. Frontend: `tsc` limpio, 245 archivos / 2991 tests.

## Sugerencias (no bloqueantes)
1. Los entornos con datos pierden las etiquetas en español de los campos de la ficha técnica que ya existían (el JSONB solo guarda claves): quedan con la clave legible y se renombran en Ajustes → Catálogos.
2. `technical_spec` ya no se escribe desde la aplicación (la ficha es el documento): el catálogo de campos solo gobierna datos existentes e importaciones.
3. Se retiró el e2e `preqa-scrum425-426-technical-spec.spec.ts` (probaba un formulario de 12 campos que ya no existe; no corría en CI).
4. Una categoría desactivada con productos sigue mostrándose con su clave legible hasta que se reactive o se reasigne.
5. Los e2e renombrados no se ejecutaron (no corren en CI).
