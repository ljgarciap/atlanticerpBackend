<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

/** Batch 21 (REQ-541 RN3) — motivo obligatorio para rechazar un reporte completo. */
class RejectPettyCashReportRequest extends PettyCashMotivoRequest {}
