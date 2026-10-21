<?php

namespace App\Support;

/**
 * Audit entries for platform-administrator actions that are not changes to a saved record (checking a
 * domain, revoking sessions, sending a reset link). Changes to records are captured by the Audited trait.
 */
final class AdminAudit
{
    /**
     * @param  array<string, scalar|null>  $context  ids and short facts only, never secrets
     */
    public static function record(string $action, array $context = []): void
    {
        Audit::record($action, changes: $context, tenantId: $context['tenant_id'] ?? null);
    }
}
