<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 14i (docs/specs/fase14i-etiquetas-catalogo-multiidioma.md) — etiquetas de catálogo por idioma. `label` sigue siendo la etiqueta BASE (español);
 * `labels` (jsonb) guarda las traducciones por idioma (`{"en": "Bank deposit"}`). Siembra el inglés de las entradas que ya lo tenían en la interfaz
 * (formas de pago, motivos, especialidades, categorías…); el resto queda sin traducción y cae a la etiqueta base. Solo escribe donde `labels` está vacío:
 * nunca pisa una traducción que alguien ya escribió. Aditiva; `down()` quita la columna.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var array<string, array<string, string>> dominio => clave => etiqueta en inglés */
    private const INGLES = [
        'adjustment_reason' => [
            'CONTEO_FISICO_NO_COINCIDE' => 'Physical count doesn\'t match the system',
            'PRODUCTO_DANADO_ROTO' => 'Damaged/broken product',
            'PRODUCTO_VENCIDO_OBSOLETO' => 'Expired/obsolete product',
            'ERROR_DE_REGISTRO' => 'Recording error (entry or exit mis-keyed)',
            'ROBO_O_PERDIDA' => 'Theft or loss',
            'OTRO_ESPECIFICAR' => 'Other (specify)',
        ],
        'architect_type' => [
            'architect' => 'Architect',
            'designer' => 'Designer',
            'contractor' => 'Contractor',
            'engineer' => 'Engineer',
            'consultant' => 'Consultant',
            'other' => 'Other',
        ],
        'contact_role' => [
            'arquitecto' => 'Architect',
            'encargado' => 'Owner',
            'contacto' => 'Contact',
            'developer' => 'Developer',
        ],
        'payment_method' => [
            'transferencia' => 'Transfer',
            'cheque' => 'Check',
            'efectivo' => 'Cash',
            'tarjeta' => 'Card',
            'deposito' => 'Bank deposit',
            'link_pago' => 'Payment link',
            'retencion_impuestos' => 'Tax withholding',
            'ajuste_cuenta' => 'Account adjustment',
        ],
        'pipeline_stage' => [
            'design' => 'Design',
            'proposal' => 'Proposal',
            'approved' => 'Approved',
            'lost' => 'Lost',
            'lead' => 'Lead',
            'quote' => 'Quotation',
        ],
        'product_category' => [
            'general' => 'General',
        ],
        'provider_category' => [
            'locales' => 'Local',
            'europa' => 'Europe',
            'latinoamerica' => 'Latin America',
            'china' => 'China',
            'online' => 'Online',
            'internacional' => 'International',
        ],
        'return_reason' => [
            'danado_defectuoso' => 'Damaged / defective product',
            'no_corresponde' => 'Doesn\'t match the order',
            'cliente_cambio_opinion' => 'Client changed their mind',
            'excedente' => 'Surplus — leftover material',
            'error_instalacion' => 'Installation / measurement error',
            'otra' => 'Other (specify)',
        ],
        'technical_spec_field' => [
            'weight' => 'Weight',
            'warranty' => 'Warranty',
            'dimensions' => 'Dimensions',
            'material_finish' => 'Material / finish',
            'certifications' => 'Certifications',
        ],
        'technician_specialty' => [
            'general' => 'General',
            'warranty' => 'Warranties',
        ],
        'ticket_type' => [
            'installation' => 'Installation',
            'warranty' => 'Warranty',
            'claim' => 'Claim',
            'retrofit' => 'Retrofit',
        ],
    ];

    public function up(): void
    {
        Schema::table($this->table('catalog_entries'), function (Blueprint $table): void {
            $table->jsonb('labels')->nullable()->after('label');
        });

        foreach (self::INGLES as $dominio => $entradas) {
            foreach ($entradas as $clave => $ingles) {
                DB::table($this->table('catalog_entries'))
                    ->where('domain', $dominio)->where('key', $clave)->whereNull('labels')
                    ->update(['labels' => json_encode(['en' => $ingles])]);
            }
        }
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_entries'), function (Blueprint $table): void {
            $table->dropColumn('labels');
        });
    }
};
