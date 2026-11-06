<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 27c — aplicación autenticadora (TOTP) y códigos de respaldo. Aditiva: quien no cambia de método sigue con `email`.
 * Los secretos se guardan CIFRADOS por la aplicación (cast `encrypted`), por eso son `text`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->string('mfa_method', 10)->default('email')->after('mfa_enabled');
            $table->text('mfa_totp_secret')->nullable()->after('mfa_method');
            // Secreto de un alta a medias: solo pasa a `mfa_totp_secret` al confirmar con un código válido.
            $table->text('mfa_totp_pending_secret')->nullable()->after('mfa_totp_secret');
            // Último paso de 30 s aceptado: un código gastado no sirve otra vez dentro de su ventana.
            $table->bigInteger('mfa_totp_last_step')->nullable()->after('mfa_totp_pending_secret');
        });

        Schema::table($this->table('mfa_codes'), function (Blueprint $table) {
            // `email`: el código viaja por correo (hash en `code_hash`). `totp`: el reto solo ata el paso a una sesión; el código lo calcula la aplicación.
            $table->string('method', 10)->default('email')->after('user_id');
        });

        Schema::create($this->table('mfa_backup_codes'), function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('code_hash', 64);   // HMAC-SHA256 hex; el código en claro solo se muestra al generarlo
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on($this->table('users'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('mfa_backup_codes'));

        Schema::table($this->table('mfa_codes'), function (Blueprint $table) {
            $table->dropColumn('method');
        });

        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->dropColumn(['mfa_method', 'mfa_totp_secret', 'mfa_totp_pending_secret', 'mfa_totp_last_step']);
        });
    }
};
