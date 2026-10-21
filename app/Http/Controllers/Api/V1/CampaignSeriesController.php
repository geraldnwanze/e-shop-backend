<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\CampaignSeriesRequest;
use App\Http\Resources\CampaignSeriesResource;
use App\Models\CampaignSeries;
use App\Models\CustomerSegment;
use App\Services\Campaigns\CampaignSeriesService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Email campaigns', weight: 23)]
class CampaignSeriesController extends Controller
{
    public function __construct(private readonly CampaignSeriesService $series) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', CampaignSeries::class);

        return ApiResponse::success(CampaignSeriesResource::collection(CampaignSeries::query()->latest('created_at')->latest('id')->get()));
    }

    public function show(CampaignSeries $series): JsonResponse
    {
        $this->authorize('view', $series);

        return ApiResponse::success(CampaignSeriesResource::make($series));
    }

    /**
     * Set up a campaign that repeats every week or month, to one segment.
     */
    public function store(CampaignSeriesRequest $request): JsonResponse
    {
        $this->authorize('create', CampaignSeries::class);

        $series = $this->series->create($request->user(), CustomerSegment::query()->findOrFail($request->validated('segment_id')), $request->validated(), $this->schedule($request));

        return ApiResponse::created(CampaignSeriesResource::make($series), 'Recurring campaign created.');
    }

    /**
     * Change the message, segment or timing, or pause and resume it.
     */
    public function update(CampaignSeriesRequest $request, CampaignSeries $series): JsonResponse
    {
        $this->authorize('update', $series);

        $segment = $request->filled('segment_id') ? CustomerSegment::query()->findOrFail($request->validated('segment_id')) : null;

        return ApiResponse::success(CampaignSeriesResource::make($this->series->update($series, $request->validated(), $segment, $this->schedule($request), $request->validated('status'))), 'Recurring campaign updated.');
    }

    /**
     * Stop it for good. The campaigns it already sent stay in the history.
     */
    public function destroy(CampaignSeries $series): JsonResponse
    {
        $this->authorize('delete', $series);

        $series->delete();

        return ApiResponse::noContent('Recurring campaign deleted.');
    }

    /**
     * The timing fields that were sent, nothing else.
     *
     * @return array<string, mixed>
     */
    private function schedule(CampaignSeriesRequest $request): array
    {
        return array_intersect_key($request->validated(), array_flip(['frequency', 'day_of_week', 'day_of_month', 'send_time', 'ends_on']));
    }
}
