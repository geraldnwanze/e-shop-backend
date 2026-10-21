<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\EmailSuppressionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An address that asked one store to stop sending it promotional email (such as abandoned-cart
 * reminders). Order, payment and account emails are not affected.
 */
class EmailSuppression extends Model
{
    /** @use HasFactory<EmailSuppressionFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const SCOPE_MARKETING = 'marketing';

    protected $guarded = ['id', 'tenant_id'];

    public static function suppresses(string $email, string $scope = self::SCOPE_MARKETING): bool
    {
        return static::query()->where('email', mb_strtolower($email))->where('scope', $scope)->exists();
    }
}
