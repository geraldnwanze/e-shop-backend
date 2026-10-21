<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\ProductStatus;
use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockAlert;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Group('Storefront: Store & catalogue', weight: 10)]
class StockAlertController extends Controller
{
    /**
     * Ask to be emailed when a sold-out product (or one of its variants) is available again.
     * Repeating the request for the same email is harmless and answers the same way.
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'variant_id' => ['nullable', 'uuid'],
        ]);

        $product = Product::query()->where('status', ProductStatus::Active)->where('slug', $slug)->with('variants')->firstOrFail();

        $variant = null;
        if ($product->variants->isNotEmpty() || ! empty($data['variant_id'])) {
            $variant = $product->variants->firstWhere('id', $data['variant_id'] ?? null);

            if ($variant === null) {
                throw ValidationException::withMessages(['variant_id' => 'Choose which option you want to be told about.']);
            }
        }

        $target = $variant ?? $product;

        if (! $product->track_inventory || $target->stock > 0) {
            throw BaseApiException::conflict('This item is in stock right now.');
        }

        StockAlert::query()->whereNull('notified_at')->firstOrCreate([
            'product_id' => $product->getKey(),
            'variant_id' => $variant?->getKey(),
            'email' => $data['email'],
        ]);

        return ApiResponse::noContent("We'll email you when it's back.");
    }
}
