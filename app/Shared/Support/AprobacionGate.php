<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Models\User;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Gerencia\Models\ReglaAprobacion;

/**
 * SCRUM-161/162 (redisño 2026-08-26) — punto único de verdad para "¿puede $user aprobar/rechazar
 * solicitudes de tipo $tipo?", usado tanto por GerenciaHomeService (filtrar el listado) como por
 * GerenciaAprobacionController (autorizar la acción real) — antes cada uno tenía su propia copia
 * de un chequeo binario ("¿es aprobador de ALGO?"), sin filtrar por tipo (hallazgo Pre-QA v6/v7).
 *
 * Reglas reales por tipo, en `Shared` (no en `Gerencia`) porque cruza Compras/Bodega/Gerencia:
 *   - purchase_order: EXCLUSIVO de Gerencia (ComprasSettings.primary_approver_user_id) — a propósito SIN
 *     override de ReglaAprobacion. Es el flujo de dinero real ya endurecido una vez (SCRUM-206);
 *     extenderlo requiere una decisión aparte, no forma parte de este batch (ver
 *     docs/architecture/gerencia-epic-analisis-20260826.md).
 *   - relocation/adjustment/general_count: Gerencia, O cualquier usuario con una ReglaAprobacion
 *     activa para ese tipo específico (SCRUM-163) — esto es la extensión real que faltaba: antes
 *     una ReglaAprobacion de tipo=adjustment volvía al usuario "aprobador" de TODO, pero el gate
 *     nativo de cada controller de Bodega (`resolveApproverAccess()`) igual lo rechazaba por no ser
 *     Gerencia. Ahora esos 3 controllers consultan este mismo gate.
 *   - zona_libre: el permiso real `compras.zona-libre.approve` (Lider Compras/lider_compras, vía
 *     middleware de ruta normalmente — bypaseado cuando Gerencia delega la llamada directamente,
 *     por eso se revalida acá), O una ReglaAprobacion activa para zona_libre.
 *   - superadmin.all bypassa todo siempre (mismo criterio que cada gate nativo ya usaba).
 */
final class AprobacionGate
{
    private const TIPOS_CON_OVERRIDE_REGLA = ['relocation', 'adjustment', 'general_count', 'zona_libre'];

    public function canAct(User $user, string $tipo): bool
    {
        if ($user->hasPermission('superadmin.all')) {
            return true;
        }

        $puedeNativo = match ($tipo) {
            'purchase_order' => $this->esAprobador($user),
            'relocation', 'adjustment', 'general_count' => $this->esAprobador($user),
            'zona_libre' => $user->hasPermission('compras.zona-libre.approve'),
            default => false,
        };

        if ($puedeNativo) {
            return true;
        }

        return in_array($tipo, self::TIPOS_CON_OVERRIDE_REGLA, true) && $this->tieneReglaPara($user, $tipo);
    }

    /**
     * Fase 12b — por qué `canAct()` dijo que no, en palabras de quien lo lee: «no eres el aprobador» (identidad) o «falta un permiso». Solo se
     * llama tras un `canAct() === false`; el texto no cambia ninguna decisión.
     */
    public function motivoDenegacion(string $tipo): string
    {
        if ($tipo === 'zona_libre') {
            return 'Falta el permiso «compras.zona-libre.approve» para decidir esta solicitud.';
        }

        $alternativa = in_array($tipo, self::TIPOS_CON_OVERRIDE_REGLA, true) ? ' (o quien esté en una regla de aprobación activa para este tipo)' : '';

        return 'Solo el aprobador configurado o un suplente puede decidir esto'.$alternativa.'. No es un permiso que falte: pídele a un superadmin que te designe en Configuración global → Aprobadores.';
    }

    /**
     * @param  list<string>  $tipos
     * @return list<string> subconjunto de $tipos sobre los que $user puede actuar
     */
    public function tiposPermitidos(User $user, array $tipos): array
    {
        return array_values(array_filter($tipos, fn (string $tipo): bool => $this->canAct($user, $tipo)));
    }

    private function esAprobador(User $user): bool
    {
        $primaryApproverId = ComprasSettings::current()->primary_approver_user_id;

        // Mismo criterio permisivo que los gates nativos que reemplaza (RelocationRequestController::
        // resolveApproverAccess() etc.): sin primary_approver_user_id configurado todavía, el gate no
        // bloquea a nadie — estado inicial antes del ajuste (SCRUM-206), no un bug.
        return $primaryApproverId === null || PrimaryApprovers::isApprover($user->id, $primaryApproverId);
    }

    private function tieneReglaPara(User $user, string $tipo): bool
    {
        try {
            return ReglaAprobacion::where('activo', true)
                ->where('tipo', $tipo)
                ->whereHas('aprobadores', fn ($q) => $q->where('user_id', $user->id))
                ->exists();
        } catch (\Throwable) {
            // tabla no migrada aún en este tenant — degradar a false sin romper el caller
            return false;
        }
    }
}
