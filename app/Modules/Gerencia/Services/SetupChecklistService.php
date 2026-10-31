<?php

declare(strict_types=1);

namespace App\Modules\Gerencia\Services;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContCompanyProfile;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\VentasDiseno\Models\CatalogProduct;

/**
 * Fase 16 (5.1) — lista de verificación de primera puesta en marcha. Solo lee: cada paso se calcula con datos que ya existen
 * (perfil de empresa, cuenta bancaria, almacenamiento, almacén de despacho, aprobador, catálogo y existencias) y trae la ruta de
 * la pantalla donde se completa. No guarda estado: cuando el dato existe, el paso queda completo.
 */
final class SetupChecklistService
{
    /** @return array{completo: bool, pasos: list<array{clave: string, completo: bool, ruta: string}>} */
    public function checklist(): array
    {
        $pasos = [
            ['clave' => 'perfil_empresa', 'completo' => $this->perfilEmpresa(), 'ruta' => '/admin-contab/empresa'],
            ['clave' => 'cuenta_bancaria', 'completo' => AdminContBankAccount::query()->where('activa', true)->exists(), 'ruta' => '/admin-contab/cuentas-bancarias'],
            ['clave' => 'almacenamiento', 'completo' => $this->almacenamiento(), 'ruta' => '/configuracion/global'],
            ['clave' => 'almacen_despacho', 'completo' => Warehouse::query()->activos()->where('despacho_por_defecto', true)->exists(), 'ruta' => '/bodega/bodegas'],
            ['clave' => 'aprobador_primario', 'completo' => ComprasSettings::current()->primary_approver_user_id !== null, 'ruta' => '/compras/configuracion'],
            ['clave' => 'catalogo_productos', 'completo' => CatalogProduct::query()->exists(), 'ruta' => '/ventas-diseno/catalog'],
            ['clave' => 'existencias', 'completo' => ProductWarehouseStock::query()->where('quantity', '>', 0)->exists(), 'ruta' => '/bodega/inventario'],
        ];

        return [
            'completo' => collect($pasos)->every(fn (array $p): bool => $p['completo']),
            'pasos' => $pasos,
        ];
    }

    private function perfilEmpresa(): bool
    {
        return trim((string) AdminContCompanyProfile::current()->descripcion_corta) !== '';
    }

    /** Disco local, o S3 con credenciales y bucket: sin eso no se suben ni se aprueban proyectos con archivos. */
    private function almacenamiento(): bool
    {
        $disk = (array) config('filesystems.disks.s3-private');
        if (($disk['driver'] ?? null) === 'local') {
            return true;
        }

        return ! empty($disk['key']) && ! empty($disk['secret']) && ! empty($disk['bucket']);
    }
}
