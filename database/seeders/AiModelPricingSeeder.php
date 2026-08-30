<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\AI\Models\AiModelPricing;
use Illuminate\Database\Seeder;

class AiModelPricingSeeder extends Seeder
{
    /**
     * Tarifas de referencia al momento de escribir esto — ajustables por superadmin sin deploy
     * (ver AiModelPricingController). firstOrCreate: no pisa un valor que el superadmin ya haya
     * corregido a mano en un entorno.
     */
    public function run(): void
    {
        AiModelPricing::firstOrCreate(
            ['model' => 'claude-sonnet-5'],
            ['input_price_per_million_usd' => 3.00, 'output_price_per_million_usd' => 15.00, 'updated_by' => null, 'updated_at' => now()],
        );

        AiModelPricing::firstOrCreate(
            ['model' => 'claude-haiku-4-5-20251001'],
            ['input_price_per_million_usd' => 1.00, 'output_price_per_million_usd' => 5.00, 'updated_by' => null, 'updated_at' => now()],
        );
    }
}
