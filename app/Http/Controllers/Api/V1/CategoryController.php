<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\ApiResponse;
use App\Support\UniqueSlug;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Products & inventory', weight: 22)]
class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        return ApiResponse::success(CategoryResource::collection(
            Category::query()->with('parent')->withCount('products')->orderBy('position')->orderBy('name')->get(),
        ));
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $category = new Category($request->safe()->except(['slug', 'parent_id']));
        $category->slug = UniqueSlug::generate(Category::query(), $request->validated('slug') ?? $request->validated('name'));
        $category->parent_id = $this->parentId($request->validated('parent_id'));
        $category->save();

        return ApiResponse::created(CategoryResource::make($category->load('parent')), 'Category created.');
    }

    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return ApiResponse::success(CategoryResource::make($category->load('parent')->loadCount('products')));
    }

    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $category->fill($request->safe()->except(['slug', 'parent_id']));

        if ($request->filled('slug')) {
            $category->slug = UniqueSlug::generate(Category::query(), $request->validated('slug'), $category->getKey());
        }

        if ($request->has('parent_id')) {
            $parentId = $this->parentId($request->validated('parent_id'));

            if ($parentId === $category->getKey()) {
                abort(422, 'A category cannot be its own parent.');
            }

            $category->parent_id = $parentId;
        }

        $category->save();

        return ApiResponse::success(CategoryResource::make($category->load('parent')), 'Category updated.');
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $category->delete();

        return ApiResponse::noContent('Category deleted.');
    }

    private function parentId(?string $id): ?string
    {
        return $id === null ? null : Category::query()->where('id', $id)->value('id');
    }
}
