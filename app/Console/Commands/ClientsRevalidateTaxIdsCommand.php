<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\FacturacionElectronica\Services\TaxIdValidationService;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * SCRUM-1005 — revalidación masiva, SOLO LECTURA, de los RUC/DV de `sub_clients` contra la DGI (vía MSL), con un
 * reporte CSV de discrepancias. Los RUC/DV ya cargados se escribieron a mano y sin verificar (el DV es obligatorio
 * desde SCRUM-928, no retroactivo) y de ahí salen los rechazos 9032 al certificar.
 *
 * - NO modifica ningún dato de negocio: las correcciones se hacen desde la pantalla de edición de SCRUM-1002 (con
 *   permiso y auditoría). Esta primera versión solo admite `--dry-run` y se niega a correr sin él.
 * - Reusa `TaxIdValidationService` (SCRUM-1002): respeta "Apagado" (cero llamadas a MSL), usa el circuito propio de
 *   consultas y nunca lanza; una consulta no disponible no aborta la corrida.
 * - Límite de tasa configurable (`--rate`, consultas por minuto) para no saturar a MSL; `--from-id` para reanudar.
 * - Correr en test/prod solo con aprobación explícita de Luis y en horario de baja carga.
 *
 * `estado` (p. ej. "I") se reporta tal cual en su columna, solo como dato informativo: NO genera discrepancia. Se comprobó
 * (2026-10-01) que el sandbox de MSL devuelve "I" tanto para el RUC de la guía como para el de Atlantic, una empresa real
 * y activa, así que no significa "inactivo" y marcarlo llenaría el reporte de falsos hallazgos.
 */
final class ClientsRevalidateTaxIdsCommand extends Command
{
    /** @var string */
    protected $signature = 'clients:revalidate-tax-ids
        {--tenant=atlantic : Slug del tenant a revisar}
        {--dry-run : Obligatorio en esta versión: solo lectura, genera el reporte sin modificar datos}
        {--from-id=0 : Reanudar desde este id de sub_client (inclusive)}
        {--rate=20 : Consultas máximas por minuto a MSL (0 = sin límite)}';

    /** @var string */
    protected $description = 'SCRUM-1005 — revalida (solo lectura) RUC/DV de los SubClientes contra la DGI y genera un CSV de discrepancias';

    private const CODIGO_SIN_RUC = 'sin_ruc';

    private const COLUMNAS = [
        'id', 'master_client_id', 'razon_social_cargada', 'ruc_cargado', 'dv_cargado', 'resultado',
        'nombre_oficial', 'dv_oficial', 'estado_contribuyente', 'tipo_ruc', 'discrepancias',
    ];

    /** Pausa entre consultas a MSL; sobrescribible en pruebas. */
    protected function pausar(int $microsegundos): void
    {
        usleep($microsegundos);
    }

    public function handle(TaxIdValidationService $validacion): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Esta versión solo admite --dry-run (solo lectura). No se modificó nada.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', (string) $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error("Tenant '{$this->option('tenant')}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        if (AdminContFiscalSettings::current()->pac_ambiente === 'apagado') {
            $this->warn('La facturación electrónica está APAGADA: no se llama a MSL, no se generó reporte. Cambia el ambiente en Configuración Fiscal para revalidar.');

            return self::FAILURE;
        }

        $rate = max(0, (int) $this->option('rate'));
        $pausaUs = $rate > 0 ? (int) (60_000_000 / $rate) : 0;
        $desde = max(0, (int) $this->option('from-id'));

        $ruta = sprintf('clients/revalidate-tax-ids/%s-%s.csv', $tenant->slug, now()->format('Ymd-His'));
        $disco = Storage::disk('local');
        $disco->put($ruta, ''); // crea el directorio y el archivo; las filas se agregan por stream (append() de Storage relee y reescribe todo el archivo)
        $archivo = fopen($disco->path($ruta), 'ab');
        if ($archivo === false) {
            $this->error("No se pudo abrir el reporte para escritura: {$ruta}");

            return self::FAILURE;
        }
        fwrite($archivo, "\xEF\xBB\xBF"); // BOM UTF-8: sin él Excel muestra mal las tildes ("ANÓNIMA")
        $this->escribir($archivo, self::COLUMNAS);

        /** @var array<string, int> $conteo */
        $conteo = [];
        $total = 0;
        $noDisponibles = 0;
        $detenido = false;
        $consultasHechas = false;

        try {
            SubClient::query()->where('id', '>=', $desde)->orderBy('id')->chunkById(100, function ($lote) use ($validacion, $archivo, $pausaUs, &$conteo, &$total, &$noDisponibles, &$detenido, &$consultasHechas): bool {
                /** @var SubClient $sub */
                foreach ($lote as $sub) {
                    if ($consultasHechas && $pausaUs > 0) {
                        $this->pausar($pausaUs);
                    }

                    $fila = $this->revisar($validacion, $sub);
                    if ($fila['codigo'] !== TaxIdValidationService::CODIGO_APAGADO && $fila['codigo'] !== TaxIdValidationService::CODIGO_SIN_CREDENCIALES && $fila['codigo'] !== self::CODIGO_SIN_RUC) {
                        $consultasHechas = true;
                    }

                    $this->escribir($archivo, $fila['csv']);
                    $total++;
                    if (in_array('consulta_no_disponible', $fila['discrepancias'], true)) {
                        $noDisponibles++;
                    }
                    foreach ($fila['discrepancias'] as $d) {
                        $conteo[$d] = ($conteo[$d] ?? 0) + 1;
                    }

                    // Si a mitad de la corrida se apaga la FE o desaparece la API key, no tiene sentido seguir.
                    if (in_array($fila['codigo'], [TaxIdValidationService::CODIGO_APAGADO, TaxIdValidationService::CODIGO_SIN_CREDENCIALES], true)) {
                        $this->warn("Se detuvo en el id {$sub->id}: {$fila['codigo']}. Reanuda con --from-id={$sub->id}.");
                        $detenido = true;

                        return false;
                    }
                }

                return true;
            });
        } finally {
            fclose($archivo); // también si una consulta o la escritura lanzan una excepción
        }

        $this->info("Revisados {$total} SubClientes. Reporte: storage/app/private/{$ruta}");
        ksort($conteo);
        foreach ($conteo as $tipo => $n) {
            $this->line("  {$tipo}: {$n}");
        }

        // Un circuito de consultas abierto en plena corrida (o MSL caído) infla los "no disponible": avisar, no dejarlo pasar por limpio.
        if ($total > 0 && $noDisponibles / $total > 0.2) {
            $this->warn("{$noDisponibles} de {$total} consultas no estuvieron disponibles (MSL caído, circuito abierto o límite de tasa): el reporte es incompleto. Reintenta con --from-id y un --rate menor.");
        }

        // Un reporte truncado no debe verse como una corrida exitosa para quien la automatice.
        return $detenido ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{codigo: string, discrepancias: list<string>, csv: list<string|int|null>}
     */
    private function revisar(TaxIdValidationService $validacion, SubClient $sub): array
    {
        // SCRUM-1003 — un Consumidor Final no tiene RUC que validar: no es un hallazgo ni gasta una consulta a MSL.
        if ($sub->receptor_tipo === ReceptorTipo::CONSUMIDOR_FINAL) {
            $fila = ['codigo' => self::CODIGO_SIN_RUC, 'discrepancias' => []];
            $fila['csv'] = [$sub->id, $sub->master_client_id, $sub->business_name, $sub->tax_id, $sub->tax_id_dv, 'consumidor_final', null, null, null, null, 'ninguna'];

            return $fila;
        }

        // Un tax_id vacío no se manda a MSL (se reportaría como "RUC inexistente" y gastaría una consulta).
        if (trim((string) $sub->tax_id) === '') {
            $fila = ['codigo' => self::CODIGO_SIN_RUC, 'discrepancias' => ['sin_ruc']];
            $fila['csv'] = [$sub->id, $sub->master_client_id, $sub->business_name, $sub->tax_id, $sub->tax_id_dv, 'sin_ruc', null, null, null, null, 'sin_ruc'];

            return $fila;
        }

        $r = $validacion->validar((string) $sub->tax_id);

        $resultado = match (true) {
            ! $r['disponible'] => 'no_disponible',
            $r['valido'] === true => 'valido',
            default => 'invalido',
        };

        $discrepancias = self::discrepancias($sub, $r, $resultado);

        return [
            'codigo' => $r['codigo'],
            'discrepancias' => $discrepancias,
            'csv' => [
                $sub->id, $sub->master_client_id, $sub->business_name, $sub->tax_id, $sub->tax_id_dv, $resultado,
                $r['nombre'], $r['dv'], $r['estado'], $r['tipo_ruc'], $discrepancias === [] ? 'ninguna' : implode('|', $discrepancias),
            ],
        ];
    }

    /**
     * @param array{disponible: bool, valido: bool|null, nombre: string|null, dv: string|null, tipo_ruc: string|null, estado: string|null, codigo: string} $r
     * @return list<string>
     */
    public static function discrepancias(SubClient $sub, array $r, string $resultado): array
    {
        if ($resultado === 'no_disponible') {
            return ['consulta_no_disponible'];
        }
        if ($resultado === 'invalido') {
            return ['ruc_inexistente'];
        }

        $out = [];
        $dvCargado = $sub->tax_id_dv === null ? '' : trim((string) $sub->tax_id_dv);
        if ($dvCargado === '') {
            $out[] = 'dv_faltante';
        } elseif ($r['dv'] !== null && ltrim($dvCargado, '0') !== ltrim($r['dv'], '0')) {
            $out[] = 'dv_distinto';
        }
        if ($r['nombre'] !== null && self::normalizarNombre($sub->business_name) !== self::normalizarNombre($r['nombre'])) {
            $out[] = 'razon_social_distinta';
        }

        return $out;
    }

    /** Mayúsculas, sin tildes ni puntuación, y "SOCIEDAD ANONIMA" = "SA": la DGI y AtlanticERP abrevian distinto. */
    public static function normalizarNombre(string $nombre): string
    {
        $n = mb_strtoupper($nombre);
        $n = strtr($n, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        // La DGI escribe la forma larga y AtlanticERP suele abreviar: se igualan las más comunes.
        $n = preg_replace(['/\bSOCIEDAD ANONIMA\b/', '/\bS\.\s*A\./', '/\bS\.?\s*DE\s*R\.?\s*L\.?(?=\s|$)/', '/\bSOCIEDAD DE RESPONSABILIDAD LIMITADA\b/', '/\bCORPORACION\b/', '/\bCORP\.?(?=\s|$)/'], ['SA', 'SA', 'SRL', 'SRL', 'CORP', 'CORP'], $n) ?? $n;
        $n = preg_replace('/[^A-Z0-9]+/', '', $n) ?? $n;

        return $n;
    }

    /**
     * @param resource $archivo
     * @param list<string|int|null> $campos
     */
    private function escribir($archivo, array $campos): void
    {
        fputcsv($archivo, array_map(static fn ($v): string => self::neutralizarFormula((string) ($v ?? '')), $campos));
    }

    /** Un CSV que se abre en Excel no debe ejecutar una "fórmula" cargada a mano en la razón social. */
    private static function neutralizarFormula(string $valor): string
    {
        return $valor !== '' && in_array($valor[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($valor)
            ? "'".$valor
            : $valor;
    }
}
