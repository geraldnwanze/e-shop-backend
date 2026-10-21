<?php

it('hides the API docs outside local development unless enabled', function () {
    config(['app.api_docs_enabled' => false]);

    $this->get('/docs/api.json')->assertForbidden();
    $this->get('/docs/api')->assertForbidden();
});

it('publishes an OpenAPI document that describes the real envelope, auth and tenancy rules', function () {
    config(['app.api_docs_enabled' => true]);

    $doc = $this->getJson('/docs/api.json')->assertOk()->json();

    expect($doc['openapi'])->toStartWith('3.')
        ->and($doc['info']['description'])->toContain('X-Storefront-Host')->toContain('Idempotency-Key')->toContain('minor units')
        ->and($doc['components']['securitySchemes'])->toHaveKey('http')
        ->and($doc['paths'])->toHaveKeys(['/auth/login', '/storefront/checkout', '/storefront/payments/initialize', '/products', '/orders/{order}/refund', '/domains', '/media', '/store/theme', '/admin/tenants', '/webhooks/{provider}/{tenant}']);

    $checkout = $doc['paths']['/storefront/checkout']['post'];
    $envelope = $checkout['responses']['201']['content']['application/json']['schema']['properties'];

    expect($checkout['summary'])->toBe('Place an order from a cart')
        ->and($checkout['tags'])->toBe(['Storefront: Checkout & payments'])
        ->and(array_keys($envelope))->toBe(['status', 'statusCode', 'message', 'data', 'meta', 'requestId'])
        ->and(json_encode($envelope['data']))->toContain('OrderResource')
        ->and($doc['components']['schemas']['CheckoutRequest']['properties'])->toHaveKeys(['cart_id', 'customer', 'shipping_address']);
});

it('documents every versioned API route', function () {
    config(['app.api_docs_enabled' => true]);

    $documented = collect($this->getJson('/docs/api.json')->json('paths'))->flatMap(fn ($methods) => array_keys($methods))->count();
    // One operation per route (PUT|PATCH routes are documented once).
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'))->count();

    expect($documented)->toBe($routes);
});
