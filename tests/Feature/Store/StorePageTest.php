<?php

use App\Models\StorePage;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->h = storefrontHeaders($this->a['tenant']);
});

it('lets a merchant create, edit and delete a page with a unique slug', function () {
    $this->actingAs($this->a['owner'], 'sanctum');

    $id = $this->postJson('/api/v1/pages', ['title' => 'Shipping & Returns', 'body' => "We ship in 2 days.\n\nReturns within 7 days."])
        ->assertCreated()->assertJsonPath('data.slug', 'shipping-returns')->json('data.id');

    $this->postJson('/api/v1/pages', ['title' => 'Shipping & Returns', 'body' => 'x'])->assertCreated()->assertJsonPath('data.slug', 'shipping-returns-2');

    $this->putJson("/api/v1/pages/{$id}", ['title' => 'Delivery', 'body' => 'New text'])->assertOk()->assertJsonPath('data.title', 'Delivery');
    $this->deleteJson("/api/v1/pages/{$id}")->assertOk();

    expect(StorePage::withoutTenantScope()->count())->toBe(1);
});

it('validates the page fields', function () {
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->postJson('/api/v1/pages', ['title' => '', 'body' => ''])->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['title', 'body']]]);
    $this->postJson('/api/v1/pages', ['title' => 'A', 'body' => 'b', 'slug' => 'Bad Slug'])->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['slug']]]);
});

it('does not expose another store\'s pages to a merchant', function () {
    $foreign = StorePage::factory()->forTenant($this->b['tenant'])->create();

    $this->actingAs($this->a['owner'], 'sanctum');

    $this->getJson('/api/v1/pages')->assertJsonCount(0, 'data');
    $this->putJson("/api/v1/pages/{$foreign->id}", ['title' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/v1/pages/{$foreign->id}")->assertNotFound();
});

it('requires a signed-in merchant', function () {
    $this->getJson('/api/v1/pages')->assertUnauthorized();
});

it('serves published pages to the storefront and lists footer pages in the store payload', function () {
    $about = StorePage::factory()->forTenant($this->a['tenant'])->create(['title' => 'About us', 'slug' => 'about', 'body' => 'Hello']);
    StorePage::factory()->forTenant($this->a['tenant'])->create(['slug' => 'draft', 'is_published' => false]);
    StorePage::factory()->forTenant($this->a['tenant'])->create(['slug' => 'hidden-link', 'show_in_footer' => false]);
    StorePage::factory()->forTenant($this->b['tenant'])->create(['slug' => 'other']);

    $this->getJson('/api/v1/storefront/pages/about', $this->h)->assertOk()->assertJsonPath('data.body', 'Hello');
    $this->getJson('/api/v1/storefront/pages/hidden-link', $this->h)->assertOk();
    $this->getJson('/api/v1/storefront/pages/draft', $this->h)->assertNotFound();
    $this->getJson('/api/v1/storefront/pages/other', $this->h)->assertNotFound();

    $this->getJson('/api/v1/storefront/store', $this->h)->assertOk()->assertJsonCount(1, 'data.pages')->assertJsonPath('data.pages.0.slug', 'about');
});
