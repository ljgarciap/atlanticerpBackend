<?php

declare(strict_types=1);

namespace App\Shared\Rules;

use App\Shared\Support\SafePhotoUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** SCRUM-1038 (S-01): `photo_url` solo https y de un host de `config('catalog.photo_hosts')`. */
final class SafePhotoUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(SafePhotoUrlGuard::class)->isAllowed($value)) {
            $fail('validation.photo_url_not_allowed')->translate();
        }
    }
}
