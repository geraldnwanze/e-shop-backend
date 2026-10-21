<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\UpdateThemeRequest;
use App\Http\Resources\ThemeResource;
use App\Models\Store;
use App\Services\Themes\StoreCustomizationService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Store', weight: 21)]
class StoreThemeController extends Controller
{
    public function __construct(private readonly StoreCustomizationService $customization) {}

    public function show(): JsonResponse
    {
        $store = Store::query()->with('theme')->firstOrFail();
        $this->authorize('view', $store);

        return ApiResponse::success($this->payload($store));
    }

    public function update(UpdateThemeRequest $request): JsonResponse
    {
        $store = Store::query()->firstOrFail();
        $this->authorize('update', $store);

        $this->customization->updateTheme($store, $request->theme(), $request->validated('configuration', []));

        return ApiResponse::success($this->payload($store->refresh()->load('theme')), 'Theme updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Store $store): array
    {
        return [
            'theme' => $store->theme ? ThemeResource::make($store->theme)->resolve() : null,
            'configuration' => $this->customization->themeConfiguration($store)->config,
        ];
    }
}
