<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlatformSettingsRequest;
use App\Services\Platform\PlatformSettings;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

#[Group('Platform admin: Themes, domains & settings', weight: 43)]
class SettingsController extends Controller
{
    public function show(PlatformSettings $settings): JsonResponse
    {
        return ApiResponse::success($this->present($settings->all()));
    }

    public function update(PlatformSettingsRequest $request, PlatformSettings $settings): JsonResponse
    {
        $settings->update(Arr::dot($request->validated()));

        return ApiResponse::success($this->present($settings->all()), 'Settings saved.');
    }

    /**
     * @param  array<string, mixed>  $flat
     * @return array<string, mixed>
     */
    private function present(array $flat): array
    {
        return [
            'platform' => ['name' => $flat['platform.name'], 'support_email' => $flat['platform.support_email']],
            'registration' => ['enabled' => (bool) $flat['registration.enabled']],
            'billing' => ['trial_days' => (int) $flat['billing.trial_days']],
            'storefront' => ['default_theme' => $flat['storefront.default_theme']],
        ];
    }
}
