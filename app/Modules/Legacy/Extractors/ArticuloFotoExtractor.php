<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\ArticuloFotoExtractorContract;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * SCRUM-751 (seguimiento de SCRUM-750, épica SCRUM-749 — Migración ICG→AtlanticERP).
 * Illumination/migracion-icg-diseno.md §15.4. Trae el BLOB `ARTICULOS.FOTO` de UN artículo por
 * vez (`SELECT FOTO FROM ARTICULOS WHERE CODARTICULO = ? AND DESCATALOGADO = 'F'`) — nunca un
 * `SELECT` masivo de los ~9707 artículos con foto: a ~19GB totales (§15.1), cargar todo junto en
 * memoria/buffer del driver no es viable. El llamador (`Jobs\SyncProductoFotoJob`) despacha un
 * Job por artículo — esta clase solo resuelve UNA fila por invocación.
 *
 * ── RIESGO TÉCNICO #1 del diseño (§15.4) — cómo devuelve pdo_sqlsrv una columna `image` ─────────
 *
 * `DB::select()` (el que usa `App\Modules\Legacy\Extractors\ProductoExtractor` para
 * ARTICULOS/PRECIOSVENTA/PRECIOSCOMPRA en SCRUM-750) pasa por el wrapper de conexión de Laravel,
 * que arma y ejecuta el `PDOStatement` con la config de la conexión ya fijada en
 * `config/database.php` — no expone un mecanismo para pasar opciones de driver por-statement.
 * Para columnas de texto eso nunca importó. Para un BLOB `image`/`varbinary(max)` de hasta 125MB
 * (§15.1), sí importa: la documentación de Microsoft para pdo_sqlsrv, y varios reportes de la
 * comunidad del mismo problema, advierten que sin `PDO::SQLSRV_ATTR_ENCODING =>
 * PDO::SQLSRV_ENCODING_BINARY` el driver puede aplicar una conversión de charset/UTF-8 sobre los
 * bytes crudos de una columna binaria — corrompiendo el header BMP ('424D' = "BM") y los píxeles,
 * SIN lanzar ninguna excepción (el fallo es silencioso).
 *
 * DECISIÓN: esta clase no usa el facade `DB::select()` para esta query — toma el PDO crudo vía
 * `DB::connection('legacy_icg')->getPdo()` y setea `PDO::SQLSRV_ATTR_ENCODING =>
 * PDO::SQLSRV_ENCODING_BINARY` scopeado a ESTE statement únicamente. A propósito NO se setea a
 * nivel de conexión (`setAttribute()` sobre el objeto PDO completo): `legacy_icg` es la misma
 * conexión compartida que usa `ProductoExtractor` para las 3 tablas de texto de SCRUM-750, y
 * mutar el encoding de la conexión completa arriesgaría corromper esas columnas de texto para
 * arreglar esta. La detección de la constante usa `defined()`/`constant()` (strings) en vez de
 * referenciar `PDO::SQLSRV_*` directamente en el código — así la clase no revienta en
 * PHPStan/CI si algún día corre en un entorno sin `pdo_sqlsrv` cargado.
 *
 * ✅ VALIDADO CONTRA SQL SERVER REAL 2026-08-13 (primera corrida real, `--dry-run --limit=50`
 * contra `dev.atlanticerp.ai`) — con 2 correcciones sobre el mecanismo original:
 * 1. **BUG REAL encontrado y corregido**: pasar la opción de encoding en el array de
 *    `PDO::prepare($sql, $driverOptions)` (como hacía la versión anterior de este método)
 *    **crashea el proceso PHP completo** con "Invalid encoding" — no es una excepción catcheable,
 *    tira abajo todo el worker sin dejar rastro en `failed_jobs`. Fix: `$stmt->setAttribute()`
 *    sobre el statement YA preparado, ANTES de `bindValue()`/`execute()` — funciona correctamente,
 *    confirmado en vivo.
 * 2. El miedo original a corrupción silenciosa sin este mecanismo **no se confirmó**: comparé el
 *    mismo artículo leído CON y SIN la opción de encoding — header/tamaño idénticos y correctos en
 *    ambos casos en esta build del driver. Se mantiene igual como defensa explícita (no vale la
 *    pena apostar a que "salió bien sin ella" se sostenga para las ~8000 fotos reales).
 * 3. **Hallazgo operativo nuevo, no de corrección de datos**: el túnel VPN sostiene ~80-100 KB/s
 *    reales para binarios (medido con 3 fotos de tamaños distintos, throughput consistente) — a
 *    ese ritmo, mover los ~19GB del dataset completo (§15.1) toma del orden de 2-2.5 días
 *    corridos, no horas. No es un bug de este código, es una característica real de la
 *    infraestructura de red — documentado acá porque cambia la expectativa operativa de la
 *    corrida completa (§15.5).
 *
 * Mismo patrón de separación que `ProductoExtractor`: `mapRow()` es estático y puro (testeable
 * con arrays fake, sin ninguna conexión real — ver tests/Unit/Legacy/ArticuloFotoExtractorTest.php),
 * `extract()` es el único método que toca la red — no se testea con SQL Server real, se fakea vía
 * `ArticuloFotoExtractorContract` en los Feature tests del Job/comando (mismo patrón que
 * `FakeProductoExtractor` de SCRUM-750).
 */
final class ArticuloFotoExtractor implements ArticuloFotoExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function extract(int $codArticulo): ?string
    {
        $pdo = DB::connection($this->connection)->getPdo();

        $stmt = $pdo->prepare(self::query());

        if ($stmt === false) {
            throw new \RuntimeException("No se pudo preparar el statement contra la conexión [{$this->connection}].");
        }

        // CORREGIDO 2026-08-13 contra ICG real: pasar PDO::SQLSRV_ATTR_ENCODING en el array de
        // driver options de `PDO::prepare($sql, $driverOptions)` (como decía la versión anterior
        // de este método) CRASHEA el proceso PHP completo con "Invalid encoding" — no es una
        // excepción catcheable, tira abajo todo el worker. `$stmt->setAttribute()` sobre el
        // statement YA preparado sí funciona: confirmado en vivo (CODARTICULO=2, 640054 bytes,
        // header 'BM' correcto, ver memoria del proyecto). Comparación adicional contra ICG real:
        // el mismo artículo leído SIN esta opción (`SELECT FOTO` sin setAttribute) también llegó
        // con el header/tamaño correctos — no hay corrupción silenciosa en esta build del driver
        // — pero se mantiene igual como defensa explícita en vez de confiar en que "anduvo bien
        // sin ella" para las ~8000 fotos reales.
        if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_BINARY')) {
            $stmt->setAttribute(constant('PDO::SQLSRV_ATTR_ENCODING'), constant('PDO::SQLSRV_ENCODING_BINARY'));
        }

        $stmt->bindValue(1, $codArticulo, PDO::PARAM_INT);
        $stmt->execute();

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return self::mapRow($row === false ? null : $row);
    }

    public static function query(): string
    {
        return <<<'SQL'
            SELECT FOTO
            FROM ARTICULOS
            WHERE CODARTICULO = ? AND DESCATALOGADO = 'F'
            SQL;
    }

    /**
     * Pura — sin DB. `null` si el artículo no existe / está descatalogado (ya filtrado en SQL) o
     * `FOTO` viene NULL/vacío (~20% de los artículos activos no tienen foto, §15.1 — no es un
     * error, es un dato ausente legítimo).
     *
     * @param array<string, mixed>|null $row
     */
    public static function mapRow(?array $row): ?string
    {
        if ($row === null) {
            return null;
        }

        $foto = $row['FOTO'] ?? null;

        if ($foto === null || $foto === '') {
            return null;
        }

        return (string) $foto;
    }
}
