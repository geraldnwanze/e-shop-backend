<?php

namespace App\Services\Campaigns;

use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\EmailSuppression;
use App\Services\Customers\CustomerSegmentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who a campaign to a segment may actually reach: customers in the segment who opted in to marketing
 * (ticked the box, or subscribed to the newsletter) and have not unsubscribed since.
 */
class CampaignAudienceService
{
    public function __construct(private readonly CustomerSegmentService $segments, private readonly TenantContext $context) {}

    /**
     * Members of the segment we may email.
     *
     * @return Builder<Customer>
     */
    public function eligible(CustomerSegment $segment): Builder
    {
        return $this->notUnsubscribed($this->optedIn($this->segments->query($segment->rules)));
    }

    /**
     * @return array{inSegment: int, notOptedIn: int, unsubscribed: int, eligible: int}
     */
    public function summary(CustomerSegment $segment): array
    {
        $inSegment = $this->segments->query($segment->rules)->count();
        $optedIn = $this->optedIn($this->segments->query($segment->rules))->count();
        $eligible = $this->eligible($segment)->count();

        return ['inSegment' => $inSegment, 'notOptedIn' => $inSegment - $optedIn, 'unsubscribed' => $optedIn - $eligible, 'eligible' => $eligible];
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function optedIn(Builder $query): Builder
    {
        $tenantId = $this->context->id();

        return $query->where(fn ($q) => $q
            ->where('customers.accepts_marketing', true)
            ->orWhereExists(DB::table('newsletter_subscribers')->whereColumn('newsletter_subscribers.email', 'customers.email')->where('newsletter_subscribers.tenant_id', $tenantId)->select(DB::raw(1))));
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function notUnsubscribed(Builder $query): Builder
    {
        return $query->whereNotExists(DB::table('email_suppressions')
            ->whereColumn('email_suppressions.email', 'customers.email')
            ->where('email_suppressions.tenant_id', $this->context->id())
            ->where('email_suppressions.scope', EmailSuppression::SCOPE_MARKETING)
            ->select(DB::raw(1)));
    }
}
