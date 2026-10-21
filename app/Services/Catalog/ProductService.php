<?php

namespace App\Services\Catalog;

use App\Enums\InventoryReason;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Services\Inventory\InventoryService;
use App\Services\Platform\PlanLimits;
use App\Support\Tenancy\TenantContext;
use App\Support\UniqueSlug;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Validated product payloads (see ProductRequest) go in; tenant-scoped lookups
 * resolve category/media UUIDs, so a foreign id can never be attached.
 */
class ProductService
{
    public function __construct(private readonly InventoryService $inventory, private readonly PlanLimits $limits, private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        $this->limits->assertCanAdd($this->context->requireTenant(), 'products', Product::query()->count(), 'products');

        return DB::transaction(function () use ($data): Product {
            $product = new Product(Arr::except($data, ['slug', 'variants', 'images', 'category_id']));
            $product->slug = UniqueSlug::generate(Product::query(), $data['slug'] ?? $data['name']);
            $product->category_id = $this->categoryId($data['category_id'] ?? null);
            $product->save();
            // Pick up database defaults (track_inventory, status, stock) for fields the request omitted.
            $product->refresh();

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $data['variants'] ?? []);
            }

            if (array_key_exists('images', $data)) {
                $this->syncImages($product, $data['images'] ?? []);
            }

            $this->syncStockTotal($product);

            // Variant stock is logged per variant above; a product-level entry would double-count it.
            if ($product->track_inventory && $product->stock > 0 && $product->variants()->doesntExist()) {
                $this->inventory->record($product, null, $product->stock, InventoryReason::Initial);
            }

            return $this->fresh($product);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $product->fill(Arr::except($data, ['slug', 'variants', 'images', 'category_id', 'stock']));

            if (isset($data['slug'])) {
                $product->slug = UniqueSlug::generate(Product::query(), $data['slug'], $product->getKey());
            }

            if (array_key_exists('category_id', $data)) {
                $product->category_id = $this->categoryId($data['category_id']);
            }

            $product->save();

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $data['variants'] ?? []);
            }

            if (array_key_exists('images', $data)) {
                $this->syncImages($product, $data['images'] ?? []);
            }

            $this->syncStockTotal($product);

            return $this->fresh($product);
        });
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncVariants(Product $product, array $rows): void
    {
        $existing = $product->variants()->get()->keyBy('id');
        $keep = [];

        foreach (array_values($rows) as $position => $row) {
            $attributes = [
                'name' => $row['name'] ?? implode(' / ', array_values($row['options'])),
                'sku' => $row['sku'] ?? null,
                'options' => $row['options'],
                'price' => $row['price'] ?? null,
                'stock' => $row['stock'] ?? 0,
                'position' => $position,
            ];

            $variant = isset($row['id']) ? $existing->get($row['id']) : null;

            if ($variant !== null) {
                $variant->fill($attributes)->save();
                $keep[] = $variant->getKey();

                continue;
            }

            $variant = $product->variants()->create($attributes);
            $keep[] = $variant->getKey();

            if ($product->track_inventory && $variant->stock > 0) {
                $this->inventory->record($product, $variant, $variant->stock, InventoryReason::Initial);
            }
        }

        $product->variants()->whereNotIn('id', $keep)->delete();
    }

    /**
     * @param  list<array{media_id: string, alt?: ?string}>  $rows
     */
    private function syncImages(Product $product, array $rows): void
    {
        $media = Media::query()->whereIn('id', Arr::pluck($rows, 'media_id'))->get()->keyBy('id');
        $keep = [];

        foreach (array_values($rows) as $position => $row) {
            $item = $media->get($row['media_id']);

            if ($item === null) {
                continue;
            }

            $image = $product->images()->firstOrNew(['media_id' => $item->getKey()]);
            $image->fill(['alt' => $row['alt'] ?? $item->alt, 'position' => $position])->save();
            $keep[] = $item->getKey();
        }

        $product->images()->whereNotIn('media_id', $keep)->delete();
    }

    private function syncStockTotal(Product $product): void
    {
        $variants = $product->variants()->get();

        if ($variants->isNotEmpty()) {
            $product->forceFill(['stock' => $variants->sum('stock')])->save();
        }
    }

    private function categoryId(?string $id): ?string
    {
        return $id === null ? null : Category::query()->where('id', $id)->value('id');
    }

    private function fresh(Product $product): Product
    {
        return $product->refresh()->load(['category', 'variants', 'images.media']);
    }
}
