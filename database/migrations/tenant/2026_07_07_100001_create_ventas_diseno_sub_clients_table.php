<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('sub_clients'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('master_client_id')->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('tax_id');
            $table->string('delivery_address')->nullable();
            // Selección única (no múltiple) — ver REQ-063/067. Las "pastillas" que agrupan
            // categorías por Cliente Master se calculan en la consulta, no se almacenan ahí.
            $table->enum('category', ['a_walkin', 'b_architect_designer', 'c_bidding', 'd_referred'])
                ->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('sub_clients'));
    }
};
