<?php

use App\Models\NewsletterSubscriber;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
});

it('subscribes an email idempotently and normalises it', function () {
    http()->postJson('/api/v1/storefront/newsletter', ['email' => ' Fan@Example.com '], storefrontHeaders($this->a['tenant']))->assertOk();
    http()->postJson('/api/v1/storefront/newsletter', ['email' => 'fan@example.com'], storefrontHeaders($this->a['tenant']))->assertOk();

    expect(NewsletterSubscriber::withoutTenantScope()->count())->toBe(1)
        ->and(NewsletterSubscriber::withoutTenantScope()->first()->email)->toBe('fan@example.com');
});

it('keeps subscribers separate per store', function () {
    http()->postJson('/api/v1/storefront/newsletter', ['email' => 'fan@example.com'], storefrontHeaders($this->a['tenant']))->assertOk();
    http()->postJson('/api/v1/storefront/newsletter', ['email' => 'fan@example.com'], storefrontHeaders($this->b['tenant']))->assertOk();

    expect(NewsletterSubscriber::withoutTenantScope()->pluck('tenant_id')->sort()->values()->all())->toBe([$this->a['tenant']->id, $this->b['tenant']->id]);
});

it('validates the email and requires a resolvable store', function () {
    http()->postJson('/api/v1/storefront/newsletter', ['email' => 'nope'], storefrontHeaders($this->a['tenant']))->assertUnprocessable();
    http()->postJson('/api/v1/storefront/newsletter', ['email' => 'fan@example.com'], ['X-Storefront-Host' => 'ghost.mydomain.test'])->assertNotFound();
});

it('accepts testimonials in the homepage configuration and rejects stray config keys', function () {
    $this->actingAs($this->a['owner'], 'sanctum');
    $section = fn (array $config) => ['configuration' => ['homepage' => ['sections' => [['type' => 'testimonials', 'enabled' => true, 'config' => $config]]]]];

    $this->putJson('/api/v1/store/theme', $section(['items' => [['quote' => 'Best ice cream ever', 'author' => 'Ada']]]))
        ->assertOk()->assertJsonPath('data.configuration.homepage.sections.0.config.items.0.author', 'Ada');
    $this->putJson('/api/v1/store/theme', $section(['script' => '<script>']))->assertUnprocessable();
    $this->putJson('/api/v1/store/theme', $section(['items' => [['quote' => str_repeat('x', 301), 'author' => 'A']]]))->assertUnprocessable();
});
