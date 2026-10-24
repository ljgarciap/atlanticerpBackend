{{-- SCRUM-1156 — totales y datos de pago de la factura, compartidos por `invoice-pdf` (sin validez) e `invoice-pdf-fiscal`: una sola
     fuente para los montos (RN7), nunca dos copias que puedan divergir. `$pagoTitulo` solo cambia el rótulo del bloque de pago. --}}
    <div class="totales">
        <div class="fila"><div class="label">Subtotal</div><div class="valor">{{ $moneda }} {{ number_format($detail['subtotal'] ?? 0, 2) }}</div></div>
        @if (($detail['descuentos'] ?? 0) > 0)
            <div class="fila"><div class="label">Descuentos</div><div class="valor">-{{ $moneda }} {{ number_format($detail['descuentos'], 2) }}</div></div>
        @endif
        <div class="fila"><div class="label">ITBMS{{ ($detail['itbms_exento'] ?? false) ? ' (Exento)' : '' }}</div><div class="valor">{{ $moneda }} {{ number_format($detail['itbms'] ?? 0, 2) }}</div></div>

        @if (($detail['saldo_aplicado'] ?? 0) > 0)
            <div class="fila total-factura tachado"><div class="label">Total factura</div><div class="valor">{{ $moneda }} {{ number_format($detail['total'] ?? $detail['monto'], 2) }}</div></div>
            <div class="fila"><div class="label">Saldo a favor aplicado</div><div class="valor">-{{ $moneda }} {{ number_format($detail['saldo_aplicado'], 2) }}</div></div>
            <div class="fila total-final"><div class="label">Total a pagar</div><div class="valor">{{ $moneda }} {{ number_format($detail['total_a_pagar'], 2) }}</div></div>
        @else
            <div class="fila total-final"><div class="label">Total factura</div><div class="valor">{{ $moneda }} {{ number_format($detail['total'] ?? $detail['monto'], 2) }}</div></div>
        @endif
    </div>

    <div class="pago-block">
        <div class="pago-label">{{ $pagoTitulo ?? 'Datos de pago' }}</div>
        <div class="empresa-datos">
            Pago a: {{ $fiscal->nombre_comercial }}<br>
            Cuenta número: {{ $detail['cuenta_pago'] ?? '—' }}<br>
            Responsable: {{ $detail['responsable'] ?? '—' }}
        </div>
        @if ($detail['fecha_vencimiento'] ?? null)
            <div class="empresa-datos" style="margin-top: 6px;">Fecha de vencimiento: {{ $detail['fecha_vencimiento'] }}</div>
        @endif
    </div>

