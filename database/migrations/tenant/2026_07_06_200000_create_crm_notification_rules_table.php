<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        Schema::create($this->table('notification_rules'), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trigger_type', 20); // model_event | date_proximity

            // trigger_type = model_event
            $table->string('trigger_model', 40)->nullable();  // Project | ProjectActivity | ProjectDocument (whitelist)
            $table->string('trigger_event', 20)->nullable();  // created | updated
            $table->string('field', 60)->nullable();
            $table->string('operator', 20)->nullable();       // changed | changed_to | equals | gt | lt | gte | lte
            $table->jsonb('value')->nullable();

            // trigger_type = date_proximity (Fase 3 — columnas listas, sin lógica todavía)
            $table->string('date_field', 60)->nullable();
            $table->integer('days_before')->nullable();

            $table->jsonb('channels');                 // ['in_app','email']
            $table->string('recipient_type', 20);       // user | role | project_assignees
            $table->jsonb('recipient_value')->nullable(); // user ids / nombres de rol, null si project_assignees

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $table = $this->table('notification_rules');
        DB::statement("CREATE INDEX idx_notification_rules_trigger ON {$table} (trigger_model, trigger_event, is_active)");
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('notification_rules'));
    }
};
