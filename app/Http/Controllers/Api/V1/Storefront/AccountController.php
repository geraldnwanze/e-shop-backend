<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\StorefrontProductResource;
use App\Models\Product;
use App\Services\Orders\InvoiceService;
use App\Support\ApiResponse;
use App\Support\InvoiceDownload;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Everything here is reached through $request->user() relations, so a customer
 * can only ever see their own addresses and orders.
 */
#[Group('Storefront: Customer accounts', weight: 13)]
class AccountController extends Controller
{
    public function addresses(Request $request): JsonResponse
    {
        return ApiResponse::success(AddressResource::collection($request->user()->addresses()->latest('id')->get()));
    }

    public function storeAddress(AddressRequest $request): JsonResponse
    {
        $customer = $request->user();
        $address = $customer->addresses()->create($request->validated());

        if ($address->is_default) {
            $customer->addresses()->whereKeyNot($address->getKey())->update(['is_default' => false]);
        }

        return ApiResponse::created(AddressResource::make($address), 'Address saved.');
    }

    public function destroyAddress(Request $request, string $address): JsonResponse
    {
        $request->user()->addresses()->where('id', $address)->firstOrFail()->delete();

        return ApiResponse::noContent('Address deleted.');
    }

    /**
     * The signed-in customer's saved products. Products that are no longer on sale are left out.
     */
    public function wishlist(Request $request): JsonResponse
    {
        $products = Product::query()
            ->where('status', ProductStatus::Active)
            ->whereIn('id', $request->user()->wishlistItems()->select('product_id'))
            ->with(['category', 'variants', 'images.media'])
            ->latest('id')
            ->get();

        return ApiResponse::success(StorefrontProductResource::collection($products));
    }

    /**
     * Save a product. Saving it twice is harmless.
     */
    public function addToWishlist(Request $request, string $product): JsonResponse
    {
        $found = Product::query()->where('status', ProductStatus::Active)->whereKey($product)->firstOrFail();

        $request->user()->wishlistItems()->firstOrCreate(['product_id' => $found->getKey()]);

        return ApiResponse::noContent('Saved to your wishlist.');
    }

    public function removeFromWishlist(Request $request, string $product): JsonResponse
    {
        $request->user()->wishlistItems()->where('product_id', $product)->delete();

        return ApiResponse::noContent('Removed from your wishlist.');
    }

    public function orders(Request $request): JsonResponse
    {
        return ApiResponse::success(OrderResource::collection(
            $request->user()->orders()->where('placed_authenticated', true)->with('items')->latest('placed_at')->paginate(20),
        ));
    }

    /**
     * Download one of the signed-in customer's own invoices as a PDF.
     */
    public function invoice(Request $request, string $order, InvoiceService $invoices): Response
    {
        $found = $request->user()->orders()->where('placed_authenticated', true)->where('id', $order)->firstOrFail();

        return InvoiceDownload::response($invoices, $found);
    }

    public function order(Request $request, string $order): JsonResponse
    {
        $found = $request->user()->orders()->where('placed_authenticated', true)->with('items')->where('id', $order)->firstOrFail();

        return ApiResponse::success(OrderResource::make($found));
    }
}
