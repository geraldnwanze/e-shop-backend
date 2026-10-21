<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\StoreStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Http\Resources\ThemeResource;
use App\Models\Domain;
use App\Models\Store;
use App\Models\StorePage;
use App\Services\Themes\StoreCustomizationService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Storefront: Store & catalogue', weight: 10)]
class StorefrontController extends Controller
{
    /**
     * Public payload for the hostname resolved by ResolveTenantMiddleware:
     * store, theme, theme configuration and the public subset of settings.
     */
    public function show(StoreCustomizationService $customization): JsonResponse
    {
        $store = Store::query()->with('theme')->where('status', StoreStatus::Active)->firstOrFail();
        $settings = $customization->settings($store)->settings;

        return ApiResponse::success([
            'store' => StoreResource::make($store),
            'theme' => $store->theme ? ThemeResource::make($store->theme) : null,
            'configuration' => $customization->themeConfiguration($store)->config,
            'settings' => [
                'contact' => $settings['contact'] ?? [],
                'seo' => $settings['seo'] ?? [],
                'checkout' => $settings['checkout'] ?? [],
                'whatsapp' => $this->publicWhatsapp($settings['whatsapp'] ?? []),
                'reviews' => ['enabled' => (bool) ($settings['reviews']['enabled'] ?? config('themes.default_settings.reviews.enabled'))],
            ],
            'pages' => StorePage::query()->where('is_published', true)->where('show_in_footer', true)->orderBy('position')->orderBy('title')->get(['title', 'slug'])->map(fn (StorePage $p) => ['title' => $p->title, 'slug' => $p->slug])->all(),
            'acceptingOrders' => app(TenantContext::class)->requireTenant()->acceptsOrders(),
            'primaryDomain' => Domain::query()->where('is_primary', true)->where('is_verified', true)->value('domain'),
            'domains' => Domain::query()->where('is_verified', true)->pluck('domain'),
        ]);
    }

    /**
     * One published information page by its slug.
     */
    public function page(string $slug): JsonResponse
    {
        $page = StorePage::query()->where('is_published', true)->where('slug', $slug)->firstOrFail();

        return ApiResponse::success(['title' => $page->title, 'slug' => $page->slug, 'body' => $page->body, 'updatedAt' => $page->updated_at?->toIso8601String()]);
    }

    /**
     * The WhatsApp button is only exposed when the merchant has switched it on and gave a number.
     *
     * @param  array{enabled?: bool, number?: ?string, message?: ?string}  $whatsapp
     * @return array{enabled: bool, number: ?string, message: ?string}
     */
    private function publicWhatsapp(array $whatsapp): array
    {
        $live = ($whatsapp['enabled'] ?? false) && ! empty($whatsapp['number']);

        return [
            'enabled' => $live,
            'number' => $live ? $whatsapp['number'] : null,
            'message' => $live ? ($whatsapp['message'] ?? null) : null,
        ];
    }
}
