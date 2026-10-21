<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Str;

final class DomainNormalizer
{
    /**
     * Normalise a user-supplied domain or URL to a bare lowercase hostname:
     * strips protocol, credentials, port, path/query/fragment and trailing dots.
     * `www.` is preserved; use alternateHost() when www/apex equivalence matters.
     */
    public static function normalize(string $input): string
    {
        $host = Str::lower(trim($input));
        $host = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $host) ?? $host;
        $host = preg_split('#[/?\#]#', $host, 2)[0];
        $host = Str::afterLast($host, '@');
        $host = preg_replace('#:\d+$#', '', $host) ?? $host;

        return rtrim($host, '.');
    }

    /**
     * Syntactically valid public hostname (no IPs, no single labels).
     */
    public static function isValid(string $host): bool
    {
        if (strlen($host) > 253 || ! str_contains($host, '.')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return (bool) preg_match(
            '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
            $host,
        );
    }

    /**
     * The www <-> apex counterpart of a hostname, or null for deeper subdomains.
     */
    public static function alternateHost(string $host): ?string
    {
        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return substr_count($host, '.') === 1 ? 'www.'.$host : null;
    }

    /**
     * Returns the store label when $host is exactly `{label}.{platformDomain}`.
     */
    public static function subdomainLabel(string $host, string $platformDomain): ?string
    {
        $suffix = '.'.Str::lower($platformDomain);

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        return $label !== '' && ! str_contains($label, '.') ? $label : null;
    }
}
