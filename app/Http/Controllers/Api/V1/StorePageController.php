<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StorePageRequest;
use App\Http\Resources\StorePageResource;
use App\Models\StorePage;
use App\Support\ApiResponse;
use App\Support\UniqueSlug;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Store', weight: 30)]
class StorePageController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', StorePage::class);

        return ApiResponse::success(StorePageResource::collection(StorePage::query()->orderBy('position')->orderBy('title')->get()));
    }

    public function store(StorePageRequest $request): JsonResponse
    {
        $this->authorize('create', StorePage::class);

        $page = new StorePage($request->safe()->except('slug'));
        $page->slug = UniqueSlug::generate(StorePage::query(), $request->validated('slug') ?? $request->validated('title'));
        $page->save();

        return ApiResponse::created(StorePageResource::make($page), 'Page created.');
    }

    public function show(StorePage $page): JsonResponse
    {
        $this->authorize('view', $page);

        return ApiResponse::success(StorePageResource::make($page));
    }

    public function update(StorePageRequest $request, StorePage $page): JsonResponse
    {
        $this->authorize('update', $page);

        $page->fill($request->safe()->except('slug'));

        if ($request->filled('slug')) {
            $page->slug = UniqueSlug::generate(StorePage::query(), $request->validated('slug'), $page->getKey());
        }

        $page->save();

        return ApiResponse::success(StorePageResource::make($page), 'Page updated.');
    }

    public function destroy(StorePage $page): JsonResponse
    {
        $this->authorize('delete', $page);

        $page->delete();

        return ApiResponse::noContent('Page deleted.');
    }
}
