<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conexión fija al schema `public` — independiente del search_path que
     * SwitchTenantSchemaTask cambia cuando hay un tenant activo.
     */
    protected $connection = 'landlord';

    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->unique()->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
};
