<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        Schema::create($this->table('project_activities'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->enum('tipo', ['llamada', 'email', 'reunion', 'whatsapp', 'visita', 'otro']);
            $table->dateTime('fecha');
            $table->text('notas')->nullable();
            $table->date('siguiente')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('project_activities'));
    }
};
