<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContClientBillingProfile;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use Carbon\Carbon;

/**
 * Fase 28 (grupo 3) — días de crédito y vencimiento de una factura. Un cliente con `dias_credito`
 * propio lo usa; sin él (NULL) rige el global de la configuración fiscal. Única fuente del cálculo:
 * cartera, vencidos/por vencer, posición de caja y estado de cuenta preguntan aquí.
 *
 * Se instancia por cálculo (precarga los perfiles una vez): no es un singleton, así un cambio de
 * ajuste se ve en la siguiente petición.
 */
final class CreditTerms
{
    private int $global;

    /** @var array<int, int> master_client_id => días propios */
    private array $porCliente;

    public function __construct()
    {
        $this->global     = (int) AdminContFiscalSettings::current()->dias_credito_factura;
        $this->porCliente = AdminContClientBillingProfile::query()->whereNotNull('dias_credito')
            ->pluck('dias_credito', 'master_client_id')->map(fn ($d): int => (int) $d)->all();
    }

    public function diasPara(?int $masterClientId): int
    {
        return $masterClientId !== null ? ($this->porCliente[$masterClientId] ?? $this->global) : $this->global;
    }

    public function vencimiento(AdminContInvoice $invoice): Carbon
    {
        return $invoice->created_at->copy()->addDays($this->diasPara($invoice->master_client_id));
    }
}
