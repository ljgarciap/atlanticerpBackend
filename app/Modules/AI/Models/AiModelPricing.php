<?php

declare(strict_types=1);

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Precio por modelo de Claude, landlord-level (no depende del tenant — misma tarifa para todos).
 * Ajustable por superadmin sin deploy cuando Anthropic cambie tarifas (ver CRUD en
 * Auth\Http\Controllers\AiModelPricingController).
 *
 * @property string $model
 * @property float  $input_price_per_million_usd
 * @property float  $output_price_per_million_usd
 * @property int|null $updated_by
 * @property \Carbon\Carbon|null $updated_at
 */
class AiModelPricing extends Model
{
    public $timestamps   = false;
    public $incrementing = false;
    protected $table     = 'ai_model_pricing';
    protected $primaryKey = 'model';
    protected $keyType    = 'string';

    /** @var list<string> */
    protected $fillable = [
        'model',
        'input_price_per_million_usd',
        'output_price_per_million_usd',
        'updated_by',
        'updated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'input_price_per_million_usd'  => 'float',
            'output_price_per_million_usd' => 'float',
            'updated_at'                   => 'datetime',
        ];
    }

    public static function invalidateCache(string $model): void
    {
        Cache::forget("ai_model_pricing:{$model}");
    }

    /**
     * Costo estimado en USD para una llamada — null si no hay tarifa configurada para ese modelo
     * (nunca bloquea la llamada real a Anthropic por falta de config de precios).
     */
    public static function estimateCostUsd(string $model, int $inputTokens, int $outputTokens): ?float
    {
        /** @var self|null $pricing */
        $pricing = Cache::remember(
            "ai_model_pricing:{$model}",
            300,
            fn () => self::query()->find($model),
        );

        if ($pricing === null) {
            return null;
        }

        return ($inputTokens / 1_000_000 * $pricing->input_price_per_million_usd)
             + ($outputTokens / 1_000_000 * $pricing->output_price_per_million_usd);
    }
}
