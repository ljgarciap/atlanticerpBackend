<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-984 — permiso angosto `manuales.manage` (subir/borrar en la biblioteca de Manuales de
 * Usuario; superadmin por `superadmin.all`, Daniela vía `SpecialPermissionSeeder`) y las claves de
 * `system_settings` con los topes/formatos/TTL de la biblioteca (nunca constantes de negocio).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var array<string, string> key => JSON literal */
    private const SETTINGS = [
        'library_max_pdf_mb'          => '50',
        'library_max_video_mb'        => '500',
        'library_allowed_extensions'  => '["pdf","mp4","webm"]',
        'library_url_ttl_view_seconds'   => '900',
        'library_url_ttl_video_seconds'  => '3600',
        'library_url_ttl_upload_seconds' => '1800',
        'library_max_pending_uploads'    => '5',
    ];

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insertOrIgnore([
            'key' => 'manuales.manage', 'module' => 'manuales',
            'label' => 'Gestionar biblioteca de manuales (subir y borrar)',
            'sort_order' => 102, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (self::SETTINGS as $key => $json) {
            DB::table($this->table('system_settings'))->insertOrIgnore([
                'key'        => $key,
                'value'      => DB::raw("'{$json}'::jsonb"),
                'updated_by' => null,
                'updated_at' => null,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))->where('key', 'manuales.manage')->delete();
        DB::table($this->table('system_settings'))->whereIn('key', array_keys(self::SETTINGS))->delete();
    }
};
