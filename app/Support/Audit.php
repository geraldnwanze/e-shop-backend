<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the audit trail. Recording never breaks the action being recorded: a failure is reported and
 * the request carries on.
 */
final class Audit
{
    /** Attribute names whose values are never stored, only the fact that they changed. */
    private const SECRET = '/pass|secret|token|key|credential|authorization|signature|cvv|card/i';

    private static int $paused = 0;

    /**
     * @param  array<string, mixed>  $changes  field => [old, new], or any small facts about the action
     * @param  array<string, mixed>  $meta  extra context stored beside the request details
     */
    public static function record(string $action, ?Model $subject = null, array $changes = [], array $meta = [], ?string $tenantId = null, ?Model $actor = null, ?string $subjectLabel = null): void
    {
        if (self::$paused > 0) {
            return;
        }

        try {
            $actor ??= Auth::user();
            [$actorType, $actorId, $actorLabel] = self::describeActor($actor);
            $tenantId ??= $subject instanceof Tenant ? $subject->getKey() : ($subject?->getAttribute('tenant_id') ?? app(TenantContext::class)->id() ?? $actor?->getAttribute('tenant_id'));

            AuditLog::create([
                'tenant_id' => $tenantId,
                'tenant_label' => $tenantId ? Tenant::query()->whereKey($tenantId)->value('name') : null,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_label' => $actorLabel,
                'action' => $action,
                'subject_type' => $subject ? self::noun($subject) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subjectLabel ?? ($subject ? self::label($subject) : null),
                'changes' => $changes === [] ? null : self::redact($changes),
                'meta' => self::requestMeta() + $meta,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Run something without recording (bulk seeding, imports).
     */
    public static function withoutAuditing(callable $callback): mixed
    {
        self::$paused++;

        try {
            return $callback();
        } finally {
            self::$paused--;
        }
    }

    /**
     * The short name of a model in the trail: Product becomes "product", ShippingZone "shipping_zone".
     */
    public static function noun(Model $model): string
    {
        return Str::snake(class_basename($model));
    }

    public static function label(Model $model): ?string
    {
        foreach (['name', 'title', 'code', 'domain', 'email', 'slug', 'order_number', 'key'] as $field) {
            $value = $model->getAttribute($field);

            if (is_string($value) && $value !== '') {
                return Str::limit($value, 190, '');
            }
        }

        return null;
    }

    /**
     * Field-by-field changes of a saved model, nested arrays compared by dotted path.
     * Secrets are reduced to a marker.
     *
     * @param  list<string>  $ignore
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function diff(Model $model, array $ignore = []): array
    {
        $out = [];

        foreach ($model->getChanges() as $field => $new) {
            if (in_array($field, $ignore, true) || $field === 'updated_at') {
                continue;
            }

            $old = $model->getOriginal($field);
            $oldValue = is_string($old) && is_array($model->getCasts()[$field] ?? null) ? json_decode($old, true) : $old;
            $newValue = $model->getAttribute($field);

            if (is_array($newValue) || is_array($oldValue)) {
                $before = Arr::dot((array) $oldValue);
                $after = Arr::dot((array) $newValue);

                foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $path) {
                    if (($before[$path] ?? null) !== ($after[$path] ?? null)) {
                        $out["{$field}.{$path}"] = [$before[$path] ?? null, $after[$path] ?? null];
                    }
                }

                continue;
            }

            $out[$field] = [self::scalar($oldValue), self::scalar($newValue)];
        }

        return $out;
    }

    /**
     * Attributes of a new or removed model, secrets reduced to a marker.
     *
     * @param  list<string>  $ignore
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model, array $ignore = []): array
    {
        return collect($model->getAttributes())
            ->except([...$ignore, 'id', 'created_at', 'updated_at', 'deleted_at', 'tenant_id'])
            ->map(fn ($value) => is_string($value) || is_numeric($value) || is_bool($value) || $value === null ? $value : '[data]')
            ->all();
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET, $key)) {
                $out[$key] = is_array($value) ? ['[redacted]', '[redacted]'] : '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value);
            } else {
                $out[$key] = self::scalar($value);
            }
        }

        return $out;
    }

    private static function scalar(mixed $value): mixed
    {
        return is_string($value) ? Str::limit($value, 300, '…') : $value;
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private static function describeActor(?Model $actor): array
    {
        if ($actor instanceof User) {
            return [$actor->role === UserRole::PlatformAdmin ? 'platform_admin' : 'merchant', $actor->getKey(), $actor->email];
        }

        if ($actor instanceof Customer) {
            return ['customer', $actor->getKey(), $actor->email];
        }

        return [self::viaHttp() ? 'guest' : 'system', null, null];
    }

    /**
     * True while serving a web request (it has matched a route), false for commands, jobs and seeders.
     */
    private static function viaHttp(): bool
    {
        return app()->bound('request') && request()->route() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestMeta(): array
    {
        if (! self::viaHttp()) {
            return ['source' => 'console'];
        }

        $request = request();

        return array_filter([
            'source' => 'http',
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 200, ''),
            'request_id' => Context::get('request_id'),
        ]);
    }
}
