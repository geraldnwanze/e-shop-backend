<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use App\Support\Audit;
use App\Traits\Audited;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * `tenant_id` and `role` are deliberately not fillable: they are assigned by
 * server-side actions only.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Audited, HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    /** @var list<string> Fields that change constantly and are not worth recording. */
    protected array $auditIgnore = ['remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->role->permissions(), true);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === UserRole::PlatformAdmin;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    /**
     * Give the admin-console actions their own plain names in the audit trail.
     *
     * @param  array<string, mixed>  $changes
     */
    public function auditAction(string $event, array $changes): string
    {
        if ($event === 'updated') {
            if (array_key_exists('suspended_at', $changes)) {
                return $this->suspended_at !== null ? 'user.suspended' : 'user.reactivated';
            }

            if (array_key_exists('role', $changes)) {
                return 'user.role_changed';
            }

            if (array_keys($changes) === ['email_verified_at']) {
                return 'user.email_verified';
            }
        }

        return Audit::noun($this).'.'.$event;
    }
}
