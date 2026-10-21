<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerSegmentRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\CustomerSegmentResource;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\Store;
use App\Services\Customers\CustomerSegmentService;
use App\Support\ApiResponse;
use App\Support\CsvSafe;
use App\Support\Tenancy\TenantContext;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Merchant: Customer segments', weight: 23)]
class CustomerSegmentController extends Controller
{
    public function __construct(private readonly CustomerSegmentService $segments, private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', CustomerSegment::class);

        $segments = CustomerSegment::query()->oldest('created_at')->oldest('id')->get();
        $counts = $segments->mapWithKeys(fn (CustomerSegment $s) => [$s->id => $this->segments->count($s->rules)])->all();

        return ApiResponse::success($segments->map(fn (CustomerSegment $s) => (new CustomerSegmentResource($s, $counts))->resolve())->all());
    }

    public function store(CustomerSegmentRequest $request): JsonResponse
    {
        $this->authorize('create', CustomerSegment::class);

        if (CustomerSegment::query()->count() >= CustomerSegment::MAX_PER_STORE) {
            throw BaseApiException::conflict('You can have up to '.CustomerSegment::MAX_PER_STORE.' segments. Delete one you no longer use first.');
        }

        $segment = CustomerSegment::create($request->validated())->refresh();

        return ApiResponse::created(new CustomerSegmentResource($segment, [$segment->id => $this->segments->count($segment->rules)]), 'Segment created.');
    }

    public function show(CustomerSegment $segment): JsonResponse
    {
        $this->authorize('view', $segment);

        return ApiResponse::success(new CustomerSegmentResource($segment, [$segment->id => $this->segments->count($segment->rules)]));
    }

    public function update(CustomerSegmentRequest $request, CustomerSegment $segment): JsonResponse
    {
        $this->authorize('update', $segment);

        $segment->update($request->validated());

        return ApiResponse::success(new CustomerSegmentResource($segment, [$segment->id => $this->segments->count($segment->rules)]), 'Segment updated.');
    }

    public function destroy(CustomerSegment $segment): JsonResponse
    {
        $this->authorize('delete', $segment);

        $segment->delete();

        return ApiResponse::noContent('Segment deleted.');
    }

    /**
     * How many customers a set of rules would match, so the builder can show it before saving.
     */
    public function preview(CustomerSegmentRequest $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerSegment::class);

        return ApiResponse::success(['customersCount' => $this->segments->count($request->validated('rules'))]);
    }

    public function customers(CustomerSegment $segment): JsonResponse
    {
        $this->authorize('view', $segment);

        return ApiResponse::success(CustomerResource::collection(
            $this->segments->query($segment->rules)->withCount('orders')->orderByDesc('total_spent')->orderBy('customers.id')->paginate(25),
        ));
    }

    /**
     * The segment as a spreadsheet, for emailing tools or a mail merge.
     */
    public function export(CustomerSegment $segment): StreamedResponse
    {
        $this->authorize('view', $segment);

        $currency = Store::query()->value('currency') ?? 'NGN';
        $filename = 'segment-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', mb_strtolower($segment->name)).'.csv';
        $rules = $segment->rules;
        $tenant = $this->context->requireTenant();

        return response()->streamDownload(function () use ($rules, $tenant, $currency): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email', 'Phone', 'Has account', 'Paid orders', "Total spent ({$currency})", 'Last order', 'Joined']);

            // The body is written after the request has finished, so the store has to be entered again.
            $this->context->runAs($tenant, fn () => $this->segments->query($rules)->orderBy('customers.id')->each(function (Customer $c) use ($out): void {
                fputcsv($out, array_map(CsvSafe::cell(...), [
                    $c->fullName(),
                    $c->email,
                    $c->phone ?? '',
                    $c->hasAccount() ? 'yes' : 'no',
                    (string) (int) $c->paid_orders,
                    number_format(((int) $c->total_spent) / 100, 2, '.', ''),
                    $c->last_order_at ? Carbon::parse($c->last_order_at)->toDateString() : '',
                    $c->created_at?->toDateString() ?? '',
                ]));
            }));

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
