<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and update (PUT/PATCH) share these rules; on update every
 * top-level field is optional, and `variants` / `images`, when present, replace
 * the existing set. category/media are validated as UUIDs owned by THIS tenant.
 */
class ProductRequest extends FormRequest
{
    private const MAX_MONEY = 100_000_000_000;

    public function authorize(): bool
    {
        return true; // ProductPolicy in the controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        /** @var ?Product $product */
        $product = $this->route('product');

        $skuRule = fn (?string $ignoreVariantUuid = null) => function (string $attribute, mixed $value, \Closure $fail) use ($product): void {
            if ($value === null) {
                return;
            }

            $takenByProduct = Product::query()->withTrashed()->where('sku', $value)->when($product, fn ($q) => $q->whereKeyNot($product->getKey()))->exists();
            $takenByVariant = ProductVariant::query()->where('sku', $value)
                ->when($product, fn ($q) => $q->where('product_id', '!=', $product->getKey()))
                ->exists();

            if ($takenByProduct || $takenByVariant) {
                $fail('This SKU is already in use.');
            }
        };

        return [
            'name' => [$required, 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', $skuRule()],
            'price' => [$required, 'integer', 'min:0', 'max:'.self::MAX_MONEY],
            'compare_at_price' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_MONEY],
            'cost_price' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_MONEY],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'stock' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'track_inventory' => ['sometimes', 'boolean'],
            'category_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],

            'variants' => ['sometimes', 'array', 'max:100'],
            'variants.*.id' => ['nullable', 'uuid', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)->where('product_id', $product?->getKey())],
            'variants.*.name' => ['nullable', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:100', 'distinct', $skuRule()],
            'variants.*.options' => ['required', 'array', 'min:1', 'max:3'],
            'variants.*.options.*' => ['required', 'string', 'max:60'],
            'variants.*.price' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MONEY],
            'variants.*.stock' => ['nullable', 'integer', 'min:0', 'max:10000000'],

            'images' => ['sometimes', 'array', 'max:10'],
            'images.*.media_id' => ['required', 'uuid', 'distinct', Rule::exists('media', 'id')->where('tenant_id', $tenantId)],
            'images.*.alt' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['images.*.media_id.exists' => 'The selected image does not exist.'];
    }
}
