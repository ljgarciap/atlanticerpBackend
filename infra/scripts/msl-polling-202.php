<?php
/**
 * PRUEBA DE POLLING 202/PENDIENTE CON MSL — SCRUM-958 paso 11 (seguimiento en SCRUM-1019 punto 7).
 *
 * Identificador: msl-polling-202. Puede correrlo cualquier sesión cuando MSL confirme la ventana.
 * Usa el MISMO adapter/mapper de producción (MslPacAdapter) y la API key de SANDBOX del propio
 * entorno (config services.msl_fe.sandbox). Nunca imprime la key ni el xmlFirmado.
 *
 * Contexto: MSL simula el 202 de dos formas (correo de Edgar, 2026-10-02): (a) ventana en que apuntan el
 * sandbox a un host de Digifact caído (serie ILU, número 91001) o (b) una API key DE PRUEBA dedicada ya
 * apuntada al host caído (serie PEND): el envío da 202/PENDIENTE y MSL "habilita la certificación" cuando
 * Luis les avisa, tras lo cual `consultar` ve CERTIFICADO en < 1 min.
 *
 * API KEY: por defecto la del sandbox del entorno. Para la key de prueba de MSL pasarla SOLO por la variable
 * de entorno MSL_POLLING_API_KEY (nunca como argumento ni en un archivo; el script jamás la imprime).
 * Receptor: siempre Consumidor Final (02, sin RUC ni DV): no depende de la lista de RUC de cada key.
 *
 * USO (desde la raíz de atlanticerp-backend, en un entorno con key de sandbox — hoy solo dev):
 *   php infra/scripts/msl-polling-202.php estado                      # solo GET, no consume nada
 *   php infra/scripts/msl-polling-202.php enviar --confirmo-ventana   # POST factura (ver aviso)
 *   php infra/scripts/msl-polling-202.php consultar                   # GET; repetir hasta CERTIFICADO
 *   Opciones: --numero=91001 (default 91001) · --serie=ILU|PEND (default ILU)
 *   Con la key de prueba de MSL (serie PEND):
 *     docker compose -f infra/docker-compose.yml exec -T -e MSL_POLLING_API_KEY laravel \
 *       php infra/scripts/msl-polling-202.php estado --serie=PEND --numero=91001
 *     (exportar antes MSL_POLLING_API_KEY en la shell; `-e VAR` sin valor la hereda sin escribirla en el comando)
 * En Docker local/servidor:
 *   docker compose -f infra/docker-compose.yml exec -T laravel php infra/scripts/msl-polling-202.php estado
 *
 * SECUENCIA DE LA VENTANA (MSL avisa cuando apuntó el sandbox al host caído):
 *   1) `estado`                      -> debe dar 404 / no encontrado (número nunca enviado).
 *   2) `enviar --confirmo-ventana`   -> esperado: HTTP 202, estado pendiente.
 *   3) `consultar`                   -> esperado: pendiente.
 *   4) MSL restablece; su reintentador corre cada ~60 s.
 *   5) `consultar` (repetir ~cada 60 s) -> esperado: certificado con cufe, qr y xmlFirmado.
 *
 * AVISO: `enviar` FUERA de la ventana CERTIFICA de verdad e INCINERA ese número del sandbox
 * compartido (irreversible, y dev/test comparten key). Por eso exige --confirmo-ventana: solo
 * pasarlo cuando MSL haya dicho que el host ya está apagado. Si tras enviar sale `certificado`
 * (no 202), usar otro --numero la próxima vez; el 91001 queda consumido.
 *
 * Códigos de salida: 0 ok · 1 modo desconocido · 2 sin API key · 4 falta --confirmo-ventana.
 */

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\MslPacAdapter;
use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\MslPaymentMethodMapper;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\{FiscalDocument, FiscalDocumentItem, FiscalParty, FiscalPayment};

$root = is_file(dirname(__DIR__, 2).'/vendor/autoload.php') ? dirname(__DIR__, 2) : getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$modo = $argv[1] ?? 'estado';
$confirmo = in_array('--confirmo-ventana', $argv, true);
$numero = '91001';
$serie = 'ILU';
foreach ($argv as $a) {
    if (str_starts_with($a, '--numero=')) {
        $numero = substr($a, 9);
    }
    if (str_starts_with($a, '--serie=')) {
        $serie = substr($a, 8);
    }
}

$cfg = config('services.msl_fe.sandbox');
$apiKey = trim((string) (getenv('MSL_POLLING_API_KEY') ?: ($cfg['api_key'] ?? '')));
if ($apiKey === '') {
    fwrite(STDERR, "ABORTA: no hay API key (MSL_POLLING_API_KEY ni MSL_FE_SANDBOX_API_KEY)\n");
    exit(2);
}
$adapter = new MslPacAdapter(baseUrl: (string) $cfg['base_url'], apiKey: $apiKey, ambiente: 'pruebas');

$resumir = static function ($r): array {
    $resp = $r->rawResponse;
    unset($resp['xmlFirmado']);
    return [
        'endpoint' => $r->endpoint, 'ambiente' => $r->ambiente, 'http_status' => $r->httpStatus,
        'duracion_ms' => $r->duracionMs, 'estado' => $r->estado, 'cufe' => $r->cufe,
        'tiene_qr' => $r->qr !== null, 'tiene_xml' => $r->xmlFirmadoBase64 !== null,
        'errores' => $r->errores, 'response' => $resp,
    ];
};

$out = ['script' => 'msl-polling-202', 'modo' => $modo, 'serie' => $serie, 'key' => getenv('MSL_POLLING_API_KEY') ? 'MSL_POLLING_API_KEY (de entorno)' : 'sandbox del entorno', 'numero' => $numero, 'hora' => date('c')];

if ($modo === 'estado' || $modo === 'consultar') {
    $out['resultado'] = $resumir($adapter->consultarEstado($serie, $numero));
} elseif ($modo === 'enviar') {
    if (! $confirmo) {
        fwrite(STDERR, "ABORTA: enviar certifica de verdad fuera de la ventana. Pasar --confirmo-ventana SOLO cuando MSL confirme que el host está apagado.\n");
        exit(4);
    }
    $emisor = new FiscalParty(tipo: '01', ruc: '1838853-1-711641', dv: '81', nombre: 'ATLANTIC USA CORP');
    $receptor = FiscalParty::consumidorFinal();
    $doc = new FiscalDocument(
        tipoDocumento: 'factura', serie: $serie, numero: $numero,
        fechaEmision: new DateTimeImmutable('now', new DateTimeZone('-05:00')),
        emisor: $emisor, receptor: $receptor,
        items: [new FiscalDocumentItem(codigo: '73020F-CW', descripcion: ' TUBO LED T8 UL18W 4000K 100-347V 1900LM', cantidad: 1, precioUnitario: 30.00, tasaItbmsCode: '01')],
        pagos: [new FiscalPayment(metodoPagoInterno: MslPaymentMethodMapper::METODO_INTERNO_CREDITO, monto: 32.10)],
    );
    $r = $adapter->certificarFactura($doc);
    $out['resultado'] = $resumir($r);
    $out['esperado'] = 'http_status 202 y estado pendiente';
    if ($r->certificado()) {
        $out['aviso'] = "ATENCIÓN: certificó en vez de quedar pendiente; el número {$numero} quedó consumido. La ventana no estaba activa.";
    }
} else {
    fwrite(STDERR, "Modo desconocido: usar estado | enviar --confirmo-ventana | consultar\n");
    exit(1);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
