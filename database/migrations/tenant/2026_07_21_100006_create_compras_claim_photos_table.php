<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SCRUM-259 (REQ-196) — fotos de evidencia del reclamo. */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('claim_photos'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_id')
                ->constrained($this->table('claims'), 'id', 'claim_photos_claim_fk')
                ->cascadeOnDelete();
            $table->string('storage_key');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('claim_photos'));
    }
};
