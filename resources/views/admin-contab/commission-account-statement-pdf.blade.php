<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }
    .brand-name { font-size: 15px; font-weight: bold; color: #2a2520; }
    .brand-line { border-bottom: 2px solid #5BA5A0; margin: 6px 0 14px; }
    h1 { font-size: 14px; color: #3D7E7A; margin: 0 0 4px; }
    .sub { font-size: 10px; color: #6b7280; margin-bottom: 14px; }
    .party { margin-bottom: 12px; }
    .party-label { font-size: 9px; color: #3D7E7A; font-weight: bold; text-transform: uppercase; }
    .party-name { font-size: 13px; font-weight: bold; }
    .party-line { font-size: 10px; color: #6b7280; }
    .mes-group-title { font-size: 11px; font-weight: bold; margin: 14px 0 4px; }
    .tag { font-size: 9px; font-weight: bold; padding: 2px 6px; border-radius: 6px; }
    .tag-fijo { background: #E3F0EE; color: #3D7E7A; }
    .tag-provisional { background: #FBF1DF; color: #92600f; }
    .tag-arrastrado { color: #9ca3af; font-style: italic; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 5px; font-size: 9px; text-transform: uppercase; }
    th.numeric, td.numeric { text-align: right; }
    td { padding: 5px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }
    .totals { width: 260px; margin-left: auto; margin-top: 14px; border: 1px solid #e7e4dc; border-radius: 8px; padding: 10px 14px; background: #f7f5ef; }
    .totals-row { display: flex; justify-content: space-between; font-size: 11px; padding: 2px 0; color: #6b7280; }
    .totals-row.neg { color: #9a3f30; }
    .totals-row.grand { font-size: 14px; font-weight: bold; color: #3D7E7A; border-top: 1px solid #e7e4dc; margin-top: 6px; padding-top: 8px; }
    .footer { margin-top: 20px; padding-top: 12px; border-top: 1px dashed #e7e4dc; font-size: 9px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
    <div class="brand-name">ATLANTIC</div>
    <div class="brand-line"></div>
    <h1>Estado de cuenta de comisiones — {{ $data['mes'] }}{{ $data['mes_cerrado'] ? ' (liquidado)' : ' (en curso)' }}</h1>
    <div class="sub">Atlantic Diseño e Iluminación, S.A. · Panamá City, Panamá</div>

    <div class="party">
        <div class="party-label">Vendedor</div>
        <div class="party-name">{{ $data['vendedor_nombre'] }}</div>
        <div class="party-line">Pedidos nuevos de {{ $data['mes'] }}: ${{ number_format($data['total_pedidos_mes'], 2) }}</div>
        <div class="party-line">% aplicado: {{ $data['porcentaje'] }}% ({{ $data['porcentaje_fijo'] ? 'fijo' : 'provisional' }})</div>
    </div>

    @if ($data['mes_cerrado'])
        <div class="sub">Este estado de cuenta muestra solo lo efectivamente pagado en este mes. Lo que sigue pendiente de cobro se ve en el estado de cuenta del mes en curso.</div>
    @endif

    @foreach ($data['groups'] as $group)
        <div class="mes-group-title">
            {{ $group['mes'] }}
            <span class="tag {{ $group['porcentaje_fijo'] ? 'tag-fijo' : 'tag-provisional' }}">{{ $group['porcentaje'] }}%{{ $group['porcentaje_fijo'] ? '' : ' provisional' }}</span>
            @if ($group['arrastrado'])
                <span class="tag-arrastrado">· arrastrado de un mes ya cerrado, aún sin cobrar</span>
            @endif
        </div>
        <table>
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Pedido</th>
                    <th class="numeric">Cobrado</th>
                    <th class="numeric">Nota crédito</th>
                    <th>Estado</th>
                    <th class="numeric">Comisión</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group['pedidos'] as $p)
                <tr>
                    <td>{{ $p['cliente'] }}{{ !empty($p['compartido_con']) ? ' (compartido con ' . implode(', ', $p['compartido_con']) . ')' : '' }}</td>
                    <td>{{ $p['numero_pedido'] }}</td>
                    <td class="numeric">${{ number_format($p['total_cobrado'], 2) }}{{ $p['es_abono_parcial'] ? ' (abono parcial)' : '' }}</td>
                    <td class="numeric">{{ $p['total_nota_credito'] > 0 ? '-$' . number_format($p['total_nota_credito'], 2) . ($p['nota_credito_ref'] ? ' (' . $p['nota_credito_ref'] . ')' : '') : '—' }}</td>
                    <td>{{ $p['estado'] }}</td>
                    <td class="numeric">${{ number_format($p['monto_comision'], 2) }}{{ $p['es_estimado'] ? ' (estimado)' : '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <div class="totals">
        @if ($data['total_nota_credito'] > 0)
            <div class="totals-row neg">
                <span>Descuento total por notas crédito</span>
                <span>-${{ number_format($data['descuento_nota_credito'], 2) }}</span>
            </div>
        @endif
        <div class="totals-row grand">
            <span>{{ $data['mes_cerrado'] ? 'Total pagado este mes' : 'Ya pagada' }}</span>
            <span>${{ number_format($data['pagada'], 2) }}</span>
        </div>
        @if (! $data['mes_cerrado'])
            <div class="totals-row">
                <span>Por pagar (cliente ya pagó)</span>
                <span>${{ number_format($data['por_pagar'], 2) }}</span>
            </div>
            <div class="totals-row">
                <span>Pendiente de cobro (estimado)</span>
                <span>${{ number_format($data['pendiente_cobro'], 2) }}</span>
            </div>
        @endif
    </div>

    <div class="footer">Atlantic Diseño e Iluminación, S.A. · Documento generado por AtlanticERP · atlantic.com.pa</div>
</body>
</html>
