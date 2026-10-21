<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\UploadBrandingRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\Themes\StoreBrandingService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Store', weight: 21)]
class StoreBrandingController extends Controller
{
    public function __construct(private readonly StoreBrandingService $branding) {}

    public function uploadLogo(UploadBrandingRequest $request): JsonResponse
    {
        return $this->upload($request, 'logo');
    }

    public function uploadFavicon(UploadBrandingRequest $request): JsonResponse
    {
        return $this->upload($request, 'favicon');
    }

    public function destroyLogo(): JsonResponse
    {
        return $this->clear('logo');
    }

    public function destroyFavicon(): JsonResponse
    {
        return $this->clear('favicon');
    }

    private function upload(UploadBrandingRequest $request, string $field): JsonResponse
    {
        $store = Store::query()->firstOrFail();
        $this->authorize('update', $store);

        return ApiResponse::success(
            StoreResource::make($this->branding->setImage($store, $field, $request->file('file'))),
            ucfirst($field).' updated.',
        );
    }

    private function clear(string $field): JsonResponse
    {
        $store = Store::query()->firstOrFail();
        $this->authorize('update', $store);

        return ApiResponse::success(StoreResource::make($this->branding->clearImage($store, $field)), ucfirst($field).' removed.');
    }
}
