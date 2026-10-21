<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;

    /**
     * Token ids are UUIDs. A bearer token whose id part is not one (for example a token issued
     * before the switch from integer ids) is simply unknown; PostgreSQL would otherwise throw.
     *
     * @param  string  $token
     */
    public static function findToken($token): ?static
    {
        if (str_contains($token, '|') && ! Str::isUuid(explode('|', $token, 2)[0])) {
            return null;
        }

        return parent::findToken($token);
    }
}
