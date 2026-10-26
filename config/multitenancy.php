<?php

use Spatie\Multitenancy\Jobs\TenantAware;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\CallQueuedClosure;
use Spatie\Multitenancy\Actions\ForgetCurrentTenantAction;
use Spatie\Multitenancy\Actions\MakeQueueTenantAwareAction;
use Spatie\Multitenancy\Actions\MakeTenantCurrentAction;
use Spatie\Multitenancy\Actions\MigrateTenantAction;
use App\Shared\Multitenancy\SwitchTenantRegionTask;
use App\Shared\Multitenancy\SwitchTenantSchemaTask;
use App\Shared\Multitenancy\Tenant;

return [
    /*
     * This class is responsible for determining which tenant should be current
     * for the given request.
     *
     * This class should extend `Spatie\Multitenancy\TenantFinder\TenantFinder`
     *
     */
    'tenant_finder' => null,

    /*
     * Slug del tenant por defecto usado donde todavía no hay resolución de
     * tenant por dominio/header (ej. login, mientras T-17 no se implementa).
     */
    'default_tenant' => env('TENANT_DEFAULT'),

    /*
     * These fields are used by tenant:artisan command to match one or more tenant.
     */
    'tenant_artisan_search_fields' => [
        'id',
    ],

    /*
     * These tasks will be performed when switching tenants.
     *
     * A valid task is any class that implements Spatie\Multitenancy\Tasks\SwitchTenantTask
     */
    'switch_tenant_tasks' => [
        SwitchTenantSchemaTask::class,
        SwitchTenantRegionTask::class,
    ],

    /*
     * This class is the model used for storing configuration on tenants.
     *
     * It must  extend `Spatie\Multitenancy\Models\Tenant::class` or
     * implement `Spatie\Multitenancy\Contracts\IsTenant::class` interface
     */
    'tenant_model' => Tenant::class,

    /*
     * If there is a current tenant when dispatching a job, the id of the current tenant
     * will be automatically set on the job. When the job is executed, the set
     * tenant on the job will be made current.
     */
    'queues_are_tenant_aware_by_default' => true,

    /*
     * The connection name to reach the tenant database.
     *
     * Set to `null` to use the default connection.
     */
    'tenant_database_connection_name' => 'pgsql',

    /*
     * The connection name to reach the landlord database.
     *
     * Misma base de datos física que `pgsql`, pero fija al schema `public`
     * (donde vive la tabla `tenants`). El search_path de `pgsql` lo cambia
     * en tiempo de ejecución SwitchTenantSchemaTask según el tenant activo.
     */
    'landlord_database_connection_name' => 'landlord',

    /*
     * This key will be used to associate the current tenant in the context
     */
    'current_tenant_context_key' => 'tenantId',

    /*
     * This key will be used to bind the current tenant in the container.
     */
    'current_tenant_container_key' => 'currentTenant',

    /*
     * Set it to `true` if you like to cache the tenant(s) routes
     * in a shared file using the `SwitchRouteCacheTask`.
     */
    'shared_routes_cache' => false,

    /*
     * You can customize some of the behavior of this package by using your own custom action.
     * Your custom action should always extend the default one.
     */
    'actions' => [
        'make_tenant_current_action' => MakeTenantCurrentAction::class,
        'forget_current_tenant_action' => ForgetCurrentTenantAction::class,
        'make_queue_tenant_aware_action' => MakeQueueTenantAwareAction::class,
        'migrate_tenant' => MigrateTenantAction::class,
    ],

    /*
     * You can customize the way in which the package resolves the queueable to a job.
     *
     * For example, using the package laravel-actions (by Loris Leiva), you can
     * resolve JobDecorator to getAction() like so: JobDecorator::class => 'getAction'
     */
    'queueable_to_job' => [
        SendQueuedMailable::class => 'mailable',
        SendQueuedNotifications::class => 'notification',
        CallQueuedClosure::class => 'closure',
        CallQueuedListener::class => 'class',
        BroadcastEvent::class => 'event',
    ],

    /*
    * Interface that once implemented, will make the job tenant aware
    */
    'tenant_aware_interface' => TenantAware::class,

    /*
     * Interface that once implemented, will make the job not tenant aware
     */
    'not_tenant_aware_interface' => NotTenantAware::class,

    /*
     * Jobs tenant aware even if these don't implement the TenantAware interface.
     */
    'tenant_aware_jobs' => [
        // ...
    ],

    /*
     * Jobs not tenant aware even if these don't implement the NotTenantAware interface.
     */
    'not_tenant_aware_jobs' => [
        // ...
    ],
];
