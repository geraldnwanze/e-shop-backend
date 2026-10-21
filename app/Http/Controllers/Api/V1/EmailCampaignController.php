<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\CampaignContentRequest;
use App\Http\Resources\CampaignRecipientResource;
use App\Http\Resources\EmailCampaignResource;
use App\Models\CustomerSegment;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Campaigns\CampaignAudienceService;
use App\Services\Campaigns\CampaignService;
use App\Support\ApiResponse;
use App\Support\CsvSafe;
use App\Support\Like;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Merchant: Email campaigns', weight: 23)]
class EmailCampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns, private readonly CampaignAudienceService $audience, private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', EmailCampaign::class);

        return ApiResponse::success(EmailCampaignResource::collection(EmailCampaign::query()->with('creator')->withCount($this->engagementCounts())->latest('created_at')->latest('id')->paginate(25)));
    }

    public function show(EmailCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        return ApiResponse::success(EmailCampaignResource::make($campaign->load('creator')->loadCount($this->engagementCounts())));
    }

    /**
     * Who got the campaign and what they did with it: opened, clicked, or neither.
     */
    public function recipients(Request $request, EmailCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $recipients = $this->recipientQuery($request, $campaign)->paginate($request->integer('per_page', 25) > 0 ? min(100, $request->integer('per_page', 25)) : 25);

        return ApiResponse::success(CampaignRecipientResource::collection($recipients));
    }

    /**
     * The recipients table as a spreadsheet, for the same filter and search as on screen.
     */
    public function export(Request $request, EmailCampaign $campaign): StreamedResponse
    {
        $this->authorize('view', $campaign);

        $query = $this->recipientQuery($request, $campaign);
        $tenant = $this->context->requireTenant();
        $tracked = (bool) $campaign->tracked;
        $filename = 'campaign-'.(trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($campaign->subject)) ?? '', '-') ?: 'results').'-results.csv';
        $iso = fn ($at) => $at ? $at->toIso8601String() : '';

        return response()->streamDownload(function () use ($query, $tenant, $tracked, $iso): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Email', 'First name', 'Delivery', 'Sent at (UTC)', 'Opened', 'Times opened', 'First opened (UTC)', 'Last opened (UTC)', 'Clicked', 'Times clicked', 'First clicked (UTC)', 'Last clicked (UTC)']);

            // The body is written after the request has finished, so the store has to be entered again.
            $this->context->runAs($tenant, fn () => $query->each(function (EmailCampaignRecipient $r) use ($out, $tracked, $iso): void {
                // A campaign that was not measured has no open or click data: leave those cells empty rather than showing zeros.
                fputcsv($out, array_map(CsvSafe::cell(...), [
                    $r->email,
                    $r->first_name ?? '',
                    $r->status,
                    $iso($r->sent_at?->utc()),
                    $tracked ? ($r->open_count > 0 ? 'yes' : 'no') : '',
                    $tracked ? (string) $r->open_count : '',
                    $iso($r->first_opened_at?->utc()),
                    $iso($r->last_opened_at?->utc()),
                    $tracked ? ($r->click_count > 0 ? 'yes' : 'no') : '',
                    $tracked ? (string) $r->click_count : '',
                    $iso($r->first_clicked_at?->utc()),
                    $iso($r->last_clicked_at?->utc()),
                ]));
            }));

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * Recipients of a campaign, filtered by what they did and by email, in the order the dashboard shows.
     *
     * @return Builder<EmailCampaignRecipient>
     */
    private function recipientQuery(Request $request, EmailCampaign $campaign): Builder
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:all,opened,clicked,not_opened,failed'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $campaign->recipients()->getQuery()
            ->when(($filters['status'] ?? 'all') === 'opened', fn ($q) => $q->where('open_count', '>', 0))
            ->when(($filters['status'] ?? null) === 'clicked', fn ($q) => $q->where('click_count', '>', 0))
            ->when(($filters['status'] ?? null) === 'not_opened', fn ($q) => $q->where('status', 'sent')->where('open_count', 0))
            ->when(($filters['status'] ?? null) === 'failed', fn ($q) => $q->where('status', 'failed'))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->whereLike('email', Like::contains($term)))
            // Those who did something first (clickers, then openers), newest activity first; SQLite and PostgreSQL disagree on where NULLs sort, so say it outright.
            ->orderByRaw('case when last_clicked_at is null then 1 else 0 end')->orderByDesc('last_clicked_at')
            ->orderByRaw('case when last_opened_at is null then 1 else 0 end')->orderByDesc('last_opened_at')->orderBy('email');
    }

    /**
     * Counts of recipients who opened and who clicked, once each however many times.
     *
     * @return array<string, \Closure>
     */
    private function engagementCounts(): array
    {
        return [
            'recipients as opened_count' => fn ($q) => $q->where('open_count', '>', 0),
            'recipients as clicked_count' => fn ($q) => $q->where('click_count', '>', 0),
        ];
    }

    /**
     * Who a campaign to this segment would reach, and why the rest would not be included.
     */
    public function audience(CampaignContentRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EmailCampaign::class);

        return ApiResponse::success($this->audience->summary($this->segment($request->validated('segment_id'))));
    }

    /**
     * Email a sample to the signed-in merchant only.
     */
    public function test(CampaignContentRequest $request): JsonResponse
    {
        $this->authorize('create', EmailCampaign::class);

        $this->campaigns->sendTest($request->user(), $request->validated());

        return ApiResponse::success(['sentTo' => $request->user()->email], "Test email sent to {$request->user()->email}.");
    }

    /**
     * Send a campaign to the segment's opted-in customers. The audience is fixed at this moment and
     * the emails go out in the background.
     */
    public function store(CampaignContentRequest $request): JsonResponse
    {
        $this->authorize('create', EmailCampaign::class);

        $segment = $this->segment($request->validated('segment_id'));

        if ($request->filled('send_at')) {
            $campaign = $this->campaigns->schedule($request->user(), $segment, $request->validated(), Carbon::parse($request->validated('send_at')));

            return ApiResponse::created(EmailCampaignResource::make($campaign->load('creator')), 'Campaign scheduled.');
        }

        $campaign = $this->campaigns->send($request->user(), $segment, $request->validated());

        return ApiResponse::created(EmailCampaignResource::make($campaign->refresh()->load('creator')), 'Campaign is on its way.');
    }

    /**
     * Change the message, segment or time of a campaign that is still waiting to be sent.
     */
    public function update(CampaignContentRequest $request, EmailCampaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);

        $segment = $request->filled('segment_id') ? $this->segment($request->validated('segment_id')) : null;
        $at = $request->filled('send_at') ? Carbon::parse($request->validated('send_at')) : null;

        return ApiResponse::success(EmailCampaignResource::make($this->campaigns->reschedule($campaign, $request->validated(), $segment, $at)->load('creator')), 'Campaign updated.');
    }

    /**
     * Stop a scheduled campaign before it goes out.
     */
    public function cancel(EmailCampaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);

        return ApiResponse::success(EmailCampaignResource::make($this->campaigns->cancel($campaign)->load('creator')), 'Campaign cancelled.');
    }

    private function segment(string $id): CustomerSegment
    {
        return CustomerSegment::query()->findOrFail($id);
    }
}
