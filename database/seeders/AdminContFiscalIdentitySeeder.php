<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-857 / SCRUM-858 — la fila de Configuración Fiscal nunca fue poblada en ningún entorno, así
 * que el "Pago a" del Estado de Cuenta, el "Responsable" del bloque de pago y el pie de página /
 * RUC del Recibo de Cobro salían en blanco (rebotes de Gerencia 2026-09-10). Los datos reales de la
 * empresa los pasó Luis; van acá — mismo criterio que `AdminContPrimaryApproverBackfillSeeder` y
 * `CoreUserSeeder`: son datos de la EMPRESA, no data demo, y deben existir en cada entorno.
 *
 * Rellena por campo, solo si está vacío — nunca pisa un valor ya cargado desde Configuración Fiscal
 * (pantalla SCRUM-632→637). Idempotente por fusión. Corre después de `BusinessRoleUserSeeder` en
 * `DatabaseSeeder` (Contabilidad solo existe a partir de ese seeder, no de `CoreUserSeeder`).
 *
 * Aprobado por Luis para correr en todos los entornos, incluida producción (2026-09-13/14) —
 * corre automáticamente como parte de `db:seed --force` en cada deploy, mismo patrón que
 * `CoreUserSeeder`/`SpecialPermissionSeeder`.
 */
class AdminContFiscalIdentitySeeder extends Seeder
{
    private const RESPONSABLE_EMAIL = 'contabilidad@test.com'; // Contabilidad Test — líder Admin & Contab.

    /** @var array<string, string> nombre_comercial/razon_social/ruc/dv/direccion_fiscal */
    private const IDENTITY = [
        'razon_social'     => 'EMPRESA DEMO S.A.',
        'nombre_comercial' => 'EMPRESA DEMO S.A.',
        'ruc'              => '0000000-0-000000',
        // Fase 4a: valores de EJEMPLO. Este seeder ya NO lo llama `DatabaseSeeder` (un tenant nuevo nace sin identidad fiscal; se carga por la pantalla de Perfil de empresa).
        'dv'               => '00',
        // Sin acento a propósito — string idéntico al footer ya hardcodeado y aprobado en
        // quote-pdf.blade.php / PdfFooterText::DEFAULT_FOOTER, para que el pie de página con datos
        // fiscales cargados sea idéntico al fallback (SCRUM-857 pide consistencia con cotizaciones).
        'direccion_fiscal' => 'Calle Ejemplo 123, Ciudad Ejemplo',
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();
        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" no encontrado — AdminContFiscalIdentitySeeder omitido.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $settings = AdminContFiscalSettings::current();
            $patch    = [];

            foreach (self::IDENTITY as $field => $value) {
                if (trim((string) $settings->{$field}) === '') {
                    $patch[$field] = $value;
                }
            }

            if ($settings->responsable_administracion_user_id === null) {
                $contabilidad = User::where('email', self::RESPONSABLE_EMAIL)->first();
                if ($contabilidad === null) {
                    $this->command->warn('Usuario Contabilidad ('.self::RESPONSABLE_EMAIL.') no encontrado — responsable_administracion_user_id sigue NULL.');
                } else {
                    $patch['responsable_administracion_user_id'] = $contabilidad->id;
                }
            }

            if ($patch !== []) {
                $settings->update($patch);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
