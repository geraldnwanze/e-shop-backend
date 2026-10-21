<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\EmailCampaignFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One promotional email sent to a customer segment. The segment's name and the recipient list are
 * kept as they were at send time, so history stays true after the segment changes or is deleted.
 */
class EmailCampaign extends Model
{
    /** @use HasFactory<EmailCampaignFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    /** Waiting for its send time. The audience is chosen only when that time comes. */
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    /** It was due but could not go out (for example nobody could be emailed any more). */
    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['recipients_count' => 'integer', 'sent_count' => 'integer', 'failed_count' => 'integer', 'completed_at' => 'datetime', 'scheduled_at' => 'datetime', 'tracked' => 'boolean'];
    }

    /**
     * @return BelongsTo<CampaignSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(CampaignSeries::class, 'campaign_series_id');
    }

    /**
     * @return HasMany<EmailCampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
