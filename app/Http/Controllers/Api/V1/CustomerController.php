<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Services\Customers\CustomerSegmentService;
use App\Support\ApiResponse;
use App\Support\Like;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant: Orders & customers', weight: 23)]
class CustomerController extends Controller
{
    public function index(Request $request, CustomerSegmentService $segments): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'segment' => ['nullable', 'uuid']]);
        $search = $filters['search'] ?? null;
        $segment = isset($filters['segment']) ? CustomerSegment::query()->findOrFail($filters['segment']) : null;

        return ApiResponse::success(CustomerResource::collection(
            ($segment ? $segments->query($segment->rules) : $segments->withMetrics())->withCount('orders')
                ->when($search, fn ($q, $term) => $q->where(fn ($q) => $q
                    ->whereLike('email', Like::contains($term))
                    ->orWhereLike('first_name', Like::contains($term))
                    ->orWhereLike('last_name', Like::contains($term))))
                ->latest('customers.id')->paginate(25),
        ));
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return ApiResponse::success(CustomerResource::make($customer->loadCount('orders')));
    }
}
