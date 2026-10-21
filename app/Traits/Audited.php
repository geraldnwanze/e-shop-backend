<?php

namespace App\Traits;

use App\Support\Audit;

/**
 * Records creation, edits and removal of a model in the audit trail, with the fields that changed
 * (secrets are never stored) and who did it. A model can list noisy fields in `$auditIgnore`, and
 * rename an entry by overriding auditAction().
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn ($model) => Audit::record($model->auditAction('created', []), $model, Audit::snapshot($model, $model->auditIgnored())));

        static::updated(function ($model): void {
            $changes = Audit::diff($model, $model->auditIgnored());

            if ($changes !== []) {
                Audit::record($model->auditAction('updated', $changes), $model, $changes);
            }
        });

        static::deleted(fn ($model) => Audit::record($model->auditAction('deleted', []), $model, [], [], null, null, Audit::label($model)));
    }

    /**
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        return property_exists($this, 'auditIgnore') ? $this->auditIgnore : [];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function auditAction(string $event, array $changes): string
    {
        return Audit::noun($this).'.'.$event;
    }
}
