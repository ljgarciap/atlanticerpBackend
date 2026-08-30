<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\Request;

final class TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $host = $request->getHost();

        /** @var Tenant|null $byDomain */
        $byDomain = Tenant::where('domain', $host)->first();

        if ($byDomain !== null) {
            return $byDomain;
        }

        $slug = config('multitenancy.default_tenant');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        /** @var Tenant|null $bySlug */
        $bySlug = Tenant::where('slug', $slug)->first();

        return $bySlug;
    }
}
