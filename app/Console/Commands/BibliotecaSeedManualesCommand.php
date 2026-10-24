<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Biblioteca\Contracts\LibraryStore;
use App\Modules\Biblioteca\Services\LibraryService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-984 (B5) — carga inicial, UNA sola vez, de los 7 PDFs de "Manuales de Usuario" adjuntos al
 * ticket. Como el bucket es compartido por dev/test/prod, se corre desde UN entorno y aparece en
 * los tres. Idempotente (mismo módulo + nombre = se omite). Requiere AWS_LIBRARY_BUCKET.
 *
 * Los archivos se nombran "N. Manual de Usuario AtlanticERP - <Módulo>.pdf"; el número N (1–7) fija el
 * módulo, el nombre visible es el nombre del archivo sin número ni extensión.
 *
 *   php artisan biblioteca:seed-manuales /ruta/a/los/pdfs --dry-run
 *   php artisan biblioteca:seed-manuales /ruta/a/los/pdfs
 */
final class BibliotecaSeedManualesCommand extends Command
{
    protected $signature   = 'biblioteca:seed-manuales {directory : Carpeta con los PDFs} {--dry-run : Solo lista qué se cargaría} {--tenant=atlantic}';
    protected $description = 'Carga inicial de los PDFs de Manuales de Usuario a la biblioteca (SCRUM-984)';

    /** @var array<int, string> número de prefijo del archivo => clave de módulo */
    private const MODULE_BY_NUMBER = [
        1 => 'crm', 2 => 'ventas_diseno', 3 => 'compras', 4 => 'bodega',
        5 => 'servicios', 6 => 'admin_contabilidad', 7 => 'gerencia',
    ];

    public function handle(LibraryService $service, LibraryStore $store): int
    {
        if (! $store->isConfigured()) {
            $this->error('AWS_LIBRARY_BUCKET no está configurado: la biblioteca no está disponible en este entorno.');

            return self::FAILURE;
        }

        $dir = rtrim((string) $this->argument('directory'), '/');
        if (! is_dir($dir)) {
            $this->error("No existe la carpeta: {$dir}");

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', (string) $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error('Tenant no encontrado.');

            return self::FAILURE;
        }

        $files = glob($dir . '/*.pdf') ?: [];
        sort($files);

        $tenant->makeCurrent();
        $failed = false;

        try {
            foreach ($files as $path) {
                $base = basename($path, '.pdf');
                if (preg_match('/^(\d+)\.\s*(.+)$/u', $base, $m) !== 1 || ! isset(self::MODULE_BY_NUMBER[(int) $m[1]])) {
                    $this->warn("Omitido (nombre sin prefijo 1–7): {$base}.pdf");

                    continue;
                }

                $module = self::MODULE_BY_NUMBER[(int) $m[1]];
                $name   = $m[2];

                if ($this->option('dry-run')) {
                    $this->line("[dry-run] {$module}: {$name}");

                    continue;
                }

                try {
                    $result = $service->importLocalFile($path, $module, $name, null);
                    $this->line($result === null ? "Ya existe, omitido: {$module}: {$name}" : "Cargado: {$module}: {$name}");
                } catch (\Throwable $e) {
                    $failed = true;
                    $this->error("Falló {$module}: {$name} — {$e->getMessage()}");
                }
            }
        } finally {
            Tenant::forgetCurrent();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
