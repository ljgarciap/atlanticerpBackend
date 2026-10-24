<?php

declare(strict_types=1);

namespace App\Shared\Mail;

use Aws\Credentials\CredentialProvider;
use Aws\SesV2\SesV2Client;
use Illuminate\Mail\Transport\SesV2Transport;

/**
 * SCRUM-1178 — SES con el ROL de la instancia. La cadena de credenciales por defecto del SDK prioriza las variables de entorno y
 * las instancias traen AWS_ACCESS_KEY_ID del usuario del bucket (sin permiso de SES): el perfil de instancia se pide de forma
 * explícita. Con SES_ACCESS_KEY_ID/SES_SECRET_ACCESS_KEY (solo desarrollo local) se usan esas claves.
 */
final class SesRoleTransport
{
    public static function make(): SesV2Transport
    {
        /** @var array{key?: string|null, secret?: string|null, region?: string|null, options?: array<string, mixed>} $ses */
        $ses  = (array) config('services.ses');
        $args = ['version' => 'latest', 'region' => (string) ($ses['region'] ?? 'us-east-1')];

        $args['credentials'] = (! empty($ses['key']) && ! empty($ses['secret']))
            ? ['key' => (string) $ses['key'], 'secret' => (string) $ses['secret']]
            : CredentialProvider::memoize(CredentialProvider::instanceProfile());

        return new SesV2Transport(new SesV2Client($args), (array) ($ses['options'] ?? []));
    }
}
