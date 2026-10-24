<?php

declare(strict_types=1);

/**
 * SCRUM-1156 — textos del PDF de factura. Los literales de los rótulos fiscales (título, leyendas, motivos) viven acá y no en
 * la plantilla para poder ajustarlos sin tocar lógica (Contabilidad/Mark definen la redacción final). Sin emojis ni glifos.
 */
return [
    'titulo'             => 'Factura de Operación Interna',
    'sin_validez'        => 'Sin validez fiscal',
    'sin_validez_marca'  => 'SIN VALIDEZ FISCAL',
    'ambiente_pruebas'   => 'AMBIENTE DE PRUEBAS - SIN VALIDEZ FISCAL',
    'anulada'            => 'ANULADA',
    'anulacion_tramite'  => 'ANULACIÓN EN TRÁMITE',
    'anulacion_tramite_nota' => 'Anulación en trámite: la nota de crédito de anulación aún no está certificada ante la DGI.',

    'motivo' => [
        'pendiente'         => 'Pendiente de certificación',
        'rechazada'         => 'Rechazada por el proveedor de facturación electrónica',
        'en_espera_nc'      => 'En espera de nota de crédito',
        'no_aplicada'       => 'Facturación electrónica no aplicada a este documento',
        'legada'            => 'Factura anterior a la facturación electrónica',
        'datos_incompletos' => 'Datos fiscales incompletos',
        'sin_emitir'        => 'Factura aún no emitida',
    ],

    'clave_acceso'       => 'Consulte por la clave de acceso en:',
    'tipo_receptor'      => 'Tipo de receptor',
    'contribuyente'      => 'Contribuyente',
    'consumidor_final'   => 'CONSUMIDOR FINAL',
    'fecha_emision'      => 'Fecha de emisión',
    'fecha'              => 'Fecha',
    'serie'              => 'Serie',
    'numero'             => 'Número',
    'vendedor'           => 'Vendedor',
    'datos_cliente'      => 'Datos del cliente',
    'ruc'                => 'RUC',
    'dv'                 => 'DV',
    'cliente_referencia' => 'Cliente (referencia interna, no consta en el comprobante fiscal)',
    'uso_posterior'      => 'Emitida en Uso Posterior: la consulta en la DGI puede tardar hasta 24 h en mostrar el documento.',

    'codigo'             => 'Código',
    'descripcion'        => 'Descripción',
    'unidades'           => 'Uds.',
    'precio'             => 'Precio',
    'importe'            => 'Importe',

    'pago_titulo'        => 'Datos de pago (dato interno, no consta en el comprobante fiscal)',
    'pie'                => 'Representación gráfica generada por AtlanticERP a partir del documento certificado por el PAC. Verifique su validez con la clave de acceso en el enlace indicado.',
];
