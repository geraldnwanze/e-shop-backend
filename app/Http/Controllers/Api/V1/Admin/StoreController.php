<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StoreStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Models\Theme;
use App\Support\ApiResponse;
use App\Support\Like;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Platform admin: Tenants & stores', weight: 40)]
class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(StoreStatus::class)],
            'theme' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $stores = Store::query()->with(['tenant', 'theme'])
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereLike('name', Like::contains($term))->orWhereLike('slug', Like::contains($term))))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['theme'] ?? null, fn ($q, $v) => $q->whereHas('theme', fn ($t) => $t->where('id', $v)))
            ->latest('id')->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success(StoreResource::collection($stores));
    }

    public function show(Store $store): JsonResponse
    {
        return ApiResponse::success(StoreResource::make($store->load(['tenant', 'theme'])));
    }

    public function update(UpdateAdminStoreRequest $request, Store $store): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['theme_id'])) {
            $data['theme_id'] = Theme::query()->where('id', $data['theme_id'])->value('id');
        }

        $store->update($data);

        return ApiResponse::success(StoreResource::make($store->load(['tenant', 'theme'])), 'Store updated.');
    }
}
