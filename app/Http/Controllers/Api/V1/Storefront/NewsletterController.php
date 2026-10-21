<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Storefront: Store & catalogue', weight: 10)]
class NewsletterController extends Controller
{
    /**
     * Idempotent per (store, email). The response is identical for new and existing
     * subscribers so the endpoint cannot be used to discover who is subscribed.
     */
    public function store(Request $request): JsonResponse
    {
        $email = Str::lower(trim((string) $request->input('email')));

        $request->merge(['email' => $email])->validate(['email' => ['required', 'email:rfc', 'max:255']]);

        NewsletterSubscriber::query()->firstOrCreate(['email' => $email], ['subscribed_at' => now()]);

        return ApiResponse::noContent('Thanks for subscribing!');
    }
}
