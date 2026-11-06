<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Fase 27a (a5): ningún correo al usuario menciona un «CRM» que ya no existe como módulo aparte. */
final class EmailTextsContractTest extends TestCase
{
    public function test_los_correos_no_mencionan_atlanticerp_crm(): void
    {
        $root  = dirname(__DIR__, 2);
        $found = [];

        foreach (['resources/views/emails', 'lang', 'app/Modules/Auth/Mail'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), 'AtlanticERP CRM')) {
                    $found[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $found, 'Estos archivos todavía dicen «AtlanticERP CRM»: ' . implode(', ', $found));
    }
}
