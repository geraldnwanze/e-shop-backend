<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\CampaignSeriesFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An email campaign that repeats (every week or month) to one segment. Each time it comes due it
 * sends an ordinary campaign, so every run has its own history and its own audience.
 */
class CampaignSeries extends Model
{
    /** @use HasFactory<CampaignSeriesFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'campaign_series';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ENDED = 'ended';

    /** Series a store may have running or paused at once. */
    public const MAX_PER_STORE = 10;

    /** After this many runs in a row that could not send, the series pauses itself. */
    public const PAUSE_AFTER_FAILURES = 3;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'runs_count' => 'integer',
            'consecutive_failures' => 'integer',
            'ends_on' => 'date',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<EmailCampaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class);
    }
}
