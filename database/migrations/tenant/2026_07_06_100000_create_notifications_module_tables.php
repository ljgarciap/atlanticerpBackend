<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'notifications';

    public function up(): void
    {
        // Primer módulo agregado después del bootstrap inicial de tenant (auth/crm/
        // installations se crean en tenant:create) — el schema no existe todavía para el
        // tenant "illuminations" ya creado, así que esta migración lo crea ella misma.
        // tenant:create también lo crea de entrada para tenants nuevos (Tenant::schemas()).
        DB::statement('CREATE SCHEMA IF NOT EXISTS "'.$this->schema().'"');

        Schema::create($this->table('notification_lists'), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('members')->default('[]'); // array de user_id
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create($this->table('notification_sends'), function (Blueprint $table) {
            $table->id();
            $table->string('source', 30); // crm_rule | crm_scheduled_report | manual_circular
            $table->string('source_ref')->nullable();
            $table->jsonb('content'); // { subject, body, cta_url? } ya resuelto
            $table->jsonb('audience'); // array de user_id, snapshot al crear el send
            $table->jsonb('channels'); // ['in_app','email']
            $table->timestampTz('scheduled_at')->nullable(); // null = inmediato
            $table->timestampTz('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create($this->table('notifications'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('notification_send_id')->nullable()
                ->constrained('notification_sends')->nullOnDelete();
            $table->jsonb('content'); // copia independiente del content del send
            $table->text('link_url')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create($this->table('notification_deliveries'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_send_id')->constrained('notification_sends')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 20); // email
            $table->string('status', 20); // sent | failed
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        $notifications = $this->table('notifications');
        DB::statement("CREATE INDEX idx_notifications_user_feed ON {$notifications} (user_id, archived_at, read_at, created_at DESC)");

        $sends = $this->table('notification_sends');
        DB::statement("CREATE INDEX idx_notification_sends_pending ON {$sends} (scheduled_at) WHERE sent_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('notification_deliveries'));
        Schema::dropIfExists($this->table('notifications'));
        Schema::dropIfExists($this->table('notification_sends'));
        Schema::dropIfExists($this->table('notification_lists'));
    }
};
