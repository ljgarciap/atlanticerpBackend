<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // SCRUM-1178 — el correo sale por SES con el ROL de la instancia (cadena de credenciales por defecto del SDK): sin claves en el
    // .env. SES_ACCESS_KEY_ID/SES_SECRET_ACCESS_KEY existen solo para desarrollo local y NO se reutilizan AWS_ACCESS_KEY_ID (que es del bucket).
    'ses' => [
        'key' => env('SES_ACCESS_KEY_ID'),
        'secret' => env('SES_SECRET_ACCESS_KEY'),
        'region' => env('SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        // Rebotes y quejas llegan a CloudWatch/SNS por el conjunto de configuración (vacío = sin conjunto).
        'options' => array_filter(['ConfigurationSetName' => env('SES_CONFIGURATION_SET')]),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    // SCRUM-943 — 2 sets de credenciales simultáneos (antes 1 solo, seleccionado por
    // MSL_FE_AMBIENTE): el ambiente real a usar por certificación ya no es una constante de
    // despliegue, es `fiscal_settings.pac_ambiente` (dato de negocio, editable por Mark en
    // Configuración Fiscal) — ver PacProviderFactory. Ambos sets viven en el .env de CADA
    // entorno de AtlanticERP (dev/test/prod) a la vez, nunca uno solo por entorno.
    'msl_fe' => [
        'sandbox' => [
            'base_url' => env('MSL_FE_SANDBOX_BASE_URL', 'https://fedigifact.appmsl.com'),
            'api_key' => env('MSL_FE_SANDBOX_API_KEY'),
        ],
        'production' => [
            'base_url' => env('MSL_FE_PROD_BASE_URL', 'https://fedigifact.appmsl.com'),
            'api_key' => env('MSL_FE_PROD_API_KEY'),
        ],
    ],

    // Outlook / Microsoft Graph (Calendario — SCRUM-66/177). App-only (client_credentials),
    // ver App\Modules\Integrations\Outlook\Services\OutlookCalendarService.
    'azure' => [
        'tenant_id'     => env('AZURE_TENANT_ID'),
        'client_id'     => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
    ],

    // Digifact — PAC directo para Facturación Electrónica (Panamá/DGI), prep sin ticket todavía,
    // ver docs/architecture/facturacion-electronica-pac-diseno.md. NUNCA usar en llamadas de red
    // reales sin que Luis/Senior Review confirmen — esta pasada solo construye el adapter contra
    // mocks. `branch_code` (Seller.BranchInfo.Code del NUC) no tiene equivalente hoy en
    // AdminContFiscalSettings — queda acá como config pendiente, no bloqueante.
    'digifact' => [
        'base_url_pruebas'     => env('DIGIFACT_BASE_URL_PRUEBAS', 'https://testnucpa.digifact.com/api'),
        'base_url_produccion'  => env('DIGIFACT_BASE_URL_PRODUCCION', 'https://nucpa.digifact.com/api'),
        'ruc'                  => env('DIGIFACT_RUC'),
        'username'             => env('DIGIFACT_USERNAME'),
        'password'             => env('DIGIFACT_PASSWORD'),
        'branch_code'          => env('DIGIFACT_BRANCH_CODE'),
    ],

];
