<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ApiErrorCodeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ThemeRequest;
use App\Http\Resources\ThemeResource;
use App\Models\Theme;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Platform admin: Themes, domains & settings', weight: 43)]
class ThemeController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(ThemeResource::collection(Theme::query()->withCount('stores')->orderBy('name')->get()));
    }

    public function store(ThemeRequest $request): JsonResponse
    {
        $theme = Theme::create($request->validated());

        return ApiResponse::created(ThemeResource::make($theme->loadCount('stores')), 'Theme created.');
    }

    public function update(ThemeRequest $request, Theme $theme): JsonResponse
    {
        $theme->update($request->validated());

        return ApiResponse::success(ThemeResource::make($theme->loadCount('stores')), 'Theme updated.');
    }

    public function destroy(Theme $theme): JsonResponse
    {
        if ($theme->stores()->exists()) {
            return ApiResponse::error(ApiErrorCodeEnum::Conflict, 'Stores are using this theme. Deactivate it instead of deleting it.');
        }

        $theme->delete();

        return ApiResponse::noContent('Theme deleted.');
    }
}
