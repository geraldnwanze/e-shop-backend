<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\DiscountRequest;
use App\Http\Resources\DiscountResource;
use App\Models\Discount;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant: Orders & customers', weight: 23)]
class DiscountController extends Controller
{
    /**
     * Codes a merchant created, by default. `source=automatic` lists the single-use codes cart
     * reminders issue, and `source=all` lists both.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Discount::class);

        $source = $request->validate(['source' => ['nullable', 'in:manual,automatic,all']])['source'] ?? 'manual';

        $discounts = Discount::query()
            ->when($source === 'manual', fn ($q) => $q->where('source', Discount::SOURCE_MANUAL))
            ->when($source === 'automatic', fn ($q) => $q->where('source', Discount::SOURCE_CART_RECOVERY))
            ->latest('id')->paginate(25);

        return ApiResponse::success(DiscountResource::collection($discounts));
    }

    public function store(DiscountRequest $request): JsonResponse
    {
        $this->authorize('create', Discount::class);

        return ApiResponse::created(DiscountResource::make(Discount::create($request->validated())->refresh()), 'Discount created.');
    }

    public function show(Discount $discount): JsonResponse
    {
        $this->authorize('view', $discount);

        return ApiResponse::success(DiscountResource::make($discount));
    }

    public function update(DiscountRequest $request, Discount $discount): JsonResponse
    {
        $this->authorize('update', $discount);

        // Automatic codes belong to the reminder email that carries them; pausing one is the only change that makes sense.
        if ($discount->isAutomatic() && array_diff(array_keys($request->validated()), ['is_active']) !== []) {
            throw BaseApiException::conflict('Codes created by cart reminders cannot be edited. You can switch one off or delete it.');
        }

        $discount->update($request->validated());

        return ApiResponse::success(DiscountResource::make($discount), 'Discount updated.');
    }

    public function destroy(Discount $discount): JsonResponse
    {
        $this->authorize('delete', $discount);

        $discount->delete();

        return ApiResponse::noContent('Discount deleted.');
    }
}
