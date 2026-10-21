<?php

namespace App\Support;

final class Like
{
    /**
     * "Contains" pattern with LIKE wildcards in the user's term escaped, for use with
     * whereLike() (case-insensitive: ILIKE on PostgreSQL, where plain LIKE is case-sensitive).
     */
    public static function contains(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
