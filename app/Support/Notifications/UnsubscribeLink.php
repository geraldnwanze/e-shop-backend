<?php

namespace App\Support\Notifications;

/**
 * The link in a promotional email that stops that store emailing that address. It carries a keyed
 * hash of the store and the address, so it cannot be forged to silence someone else, and it never
 * expires (people unsubscribe from old emails).
 */
final class UnsubscribeLink
{
    public static function url(string $tenantId, string $email): string
    {
        $email = mb_strtolower($email);

        return route('api.v1.unsubscribe.show', ['tenant' => $tenantId, 'email' => $email, 'token' => self::token($tenantId, $email)]);
    }

    public static function valid(string $tenantId, string $email, string $token): bool
    {
        return hash_equals(self::token($tenantId, mb_strtolower($email)), $token);
    }

    private static function token(string $tenantId, string $email): string
    {
        return hash_hmac('sha256', "unsubscribe|{$tenantId}|{$email}", (string) config('app.key'));
    }
}
