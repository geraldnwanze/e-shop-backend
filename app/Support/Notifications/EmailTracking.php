<?php

namespace App\Support\Notifications;

/**
 * Signed links for measuring campaign emails. Each one names a single recipient and carries a keyed
 * hash, so nobody can make up links to inflate or probe another store's numbers. The destination of
 * a click is never part of the link: it is read from the saved campaign, so these cannot be used to
 * bounce people to other sites.
 */
final class EmailTracking
{
    public static function pixelUrl(string $recipientId): string
    {
        return route('api.v1.track.open', ['token' => self::token($recipientId)]);
    }

    public static function clickUrl(string $recipientId): string
    {
        return route('api.v1.track.click', ['token' => self::token($recipientId)]);
    }

    /**
     * The recipient a link belongs to, or null when the token was not issued by us.
     */
    public static function recipientId(string $token): ?string
    {
        $token = preg_replace('/\.gif$/i', '', $token) ?? '';
        [$id, $signature] = array_pad(explode('.', $token, 2), 2, '');

        return $id !== '' && hash_equals(self::signature($id), $signature) ? $id : null;
    }

    private static function token(string $recipientId): string
    {
        return $recipientId.'.'.self::signature($recipientId);
    }

    private static function signature(string $recipientId): string
    {
        return substr(hash_hmac('sha256', "track|{$recipientId}", (string) config('app.key')), 0, 32);
    }
}
