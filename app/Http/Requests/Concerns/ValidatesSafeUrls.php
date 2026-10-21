<?php

namespace App\Http\Requests\Concerns;

use Closure;

trait ValidatesSafeUrls
{
    /**
     * Absolute https URL only (blocks javascript:, data:, http:).
     */
    protected function httpsUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && ! (is_string($value) && preg_match('#^https://[^\s/$.?\#][^\s]*$#i', $value))) {
                $fail("The {$attribute} must be a valid https URL.");
            }
        };
    }

    /**
     * Site-relative path ("/products") or absolute https URL.
     */
    protected function pathOrHttpsUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $ok = is_string($value)
                && (preg_match('#^/(?!/)[^\s]*$#', $value) || preg_match('#^https://[^\s/$.?\#][^\s]*$#i', $value));

            if ($value !== null && ! $ok) {
                $fail("The {$attribute} must be a site path or an https URL.");
            }
        };
    }
}
