<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->admin = User::factory()->platformAdmin()->create();
});

function trail(?string $action = null)
{
    return AuditLog::query()->when($action, fn ($q) => $q->where('action', $action))->orderBy('created_at')->orderBy('id')->get();
}

describe('what gets recorded', function () {
    it('records a merchant creating, editing and deleting a product, with who, where and what changed', function () {
        $this->actingAs($this->a['owner'], 'sanctum');

        $id = $this->postJson('/api/v1/products', ['name' => 'Vanilla Tub', 'price' => 250000, 'status' => 'active'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/products/{$id}", ['price' => 275000])->assertOk();
        $this->deleteJson("/api/v1/products/{$id}")->assertOk();

        $created = trail('product.created')->first();
        $updated = trail('product.updated')->first();

        expect($created)->actor_type->toBe('merchant')->actor_id->toBe($this->a['owner']->id)->actor_label->toBe($this->a['owner']->email)
            ->tenant_id->toBe($this->a['tenant']->id)->tenant_label->toBe('Icecream')->subject_type->toBe('product')->subject_id->toBe($id)->subject_label->toBe('Vanilla Tub')
            ->and($updated->changes)->toBe(['price' => [250000, 275000]])
            ->and(trail('product.deleted'))->toHaveCount(1)
            ->and($created->meta)->toHaveKeys(['source', 'ip']);
    });

    it('ignores constantly changing fields such as stock, and edits that change nothing', function () {
        $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 10]);
        $before = trail()->count();

        app(TenantContext::class)->runAs($this->a['tenant'], function () use ($product): void {
            $product->forceFill(['stock' => 9, 'rating_average' => 4.5])->save();
            $product->forceFill(['name' => $product->name])->save();
        });

        expect(trail()->count())->toBe($before);
    });

    it('never stores secrets, only that they changed', function () {
        $this->actingAs($this->a['owner'], 'sanctum');

        $this->putJson('/api/v1/payment-gateways/paystack', ['secret_key' => 'sk_test_supersecret9876', 'public_key' => 'pk_test_abc'])->assertOk();
        $this->putJson('/api/v1/payment-gateways/paystack', ['secret_key' => 'sk_test_rotated54321'])->assertOk();

        $all = json_encode(trail()->map->only(['changes', 'meta', 'subject_label'])->all());

        expect($all)->not->toContain('supersecret9876')->not->toContain('rotated54321')
            ->and(trail('payment_gateway.updated')->last()->changes)->toHaveKey('secret_key')
            ->and(trail('payment_gateway.updated')->last()->changes['secret_key'])->toBe(['[redacted]', '[redacted]']);
    });

    it('records order status changes and refunds', function () {
        $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 5]);
        $orderId = checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]]))->assertCreated()->json('data.id');

        $this->actingAs($this->a['owner'], 'sanctum')->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'PROCESSING'])->assertOk();

        $entry = trail('order.status_changed')->last();
        expect($entry)->actor_type->toBe('merchant')->subject_id->toBe($orderId)->tenant_id->toBe($this->a['tenant']->id)
            ->and($entry->changes['status'][1])->toBe('PROCESSING');
    });

    it('records sign-ins, failed attempts, blocked accounts, sign-outs and password resets', function () {
        $owner = $this->a['owner'];

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'wrong-password'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@nowhere.test', 'password' => 'whatever1'])->assertUnauthorized();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertOk()->json('data.token');

        app('auth')->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $owner->forceFill(['suspended_at' => now()])->save();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertForbidden();

        $failed = trail('auth.login_failed');
        expect($failed)->toHaveCount(2)
            ->and($failed[0])->actor_type->toBe('guest')->tenant_id->toBe($this->a['tenant']->id)
            ->and($failed[1]->tenant_id)->toBeNull()
            ->and($failed[1]->changes)->toBe(['email' => 'nobody@nowhere.test'])
            ->and(trail('auth.login'))->toHaveCount(1)
            ->and(trail('auth.login')->first())->actor_id->toBe($owner->id)->actor_type->toBe('merchant')
            ->and(trail('auth.logout'))->toHaveCount(1)
            ->and(trail('auth.login_blocked')->first()->changes)->toBe(['reason' => 'account suspended']);
    });

    it('records a password reset', function () {
        $owner = $this->a['owner'];
        $token = Password::broker()->createToken($owner);

        $this->postJson('/api/v1/auth/reset-password', ['email' => $owner->email, 'token' => $token, 'password' => 'brand-new-pass1', 'password_confirmation' => 'brand-new-pass1'])->assertOk();

        expect(trail('auth.password_reset')->first())->actor_id->toBe($owner->id)->tenant_id->toBe($this->a['tenant']->id);
    });

    it('names platform admin actions plainly and credits the admin', function () {
        $this->actingAs($this->admin, 'sanctum');

        $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantAdmin)->create();

        $this->postJson("/api/v1/admin/tenants/{$this->a['tenant']->id}/suspend")->assertOk();
        $this->patchJson("/api/v1/admin/users/{$staff->id}", ['role' => UserRole::MerchantStaff->value])->assertOk();
        $this->postJson("/api/v1/admin/users/{$this->a['owner']->id}/revoke-tokens")->assertOk();

        expect(trail('tenant.suspended')->first())->actor_type->toBe('platform_admin')->actor_id->toBe($this->admin->id)->tenant_id->toBe($this->a['tenant']->id)
            ->and(trail('user.role_changed')->first()->changes['role'])->toBe(['merchant_admin', 'merchant_staff'])
            ->and(trail('user.revoke_tokens')->first()->actor_id)->toBe($this->admin->id);
    });

    it('cannot be edited or deleted entry by entry', function () {
        $log = AuditLog::factory()->create();

        expect(fn () => $log->update(['action' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => $log->delete())->toThrow(LogicException::class)
            ->and(AuditLog::query()->whereKey($log->id)->exists())->toBeTrue();
    });
});

describe('platform admin view', function () {
    beforeEach(function () {
        AuditLog::query()->delete(); // setup rows above were audited too; start these tests from an empty trail
        $this->old = AuditLog::factory()->create(['tenant_id' => $this->b['tenant']->id, 'tenant_label' => 'Fashion', 'actor_type' => 'merchant', 'action' => 'category.updated', 'subject_label' => 'Shoes', 'created_at' => now()->subDays(10)]);
        $this->new = AuditLog::factory()->create(['tenant_id' => $this->a['tenant']->id, 'tenant_label' => 'Icecream', 'actor_type' => 'platform_admin', 'action' => 'plan.updated', 'subject_label' => 'Pro', 'actor_label' => 'boss@platform.test']);
        $this->actingAs($this->admin, 'sanctum');
    });

    it('lists newest first with the actor, store, record and changes', function () {
        $rows = $this->getJson('/api/v1/admin/audit-logs')->assertOk()->json('data');

        expect($rows[0]['id'])->toBe($this->new->id)
            ->and($rows[0])->toMatchArray(['action' => 'plan.updated'])
            ->and($rows[0]['actor'])->toBe(['type' => 'platform_admin', 'id' => $this->new->actor_id, 'label' => 'boss@platform.test'])
            ->and($rows[0]['tenant'])->toBe(['id' => $this->a['tenant']->id, 'name' => 'Icecream'])
            ->and($rows[0]['changes'])->toBe(['price' => [1000, 1200]]);
    });

    it('filters by store, actor type, action, record, dates and free text', function () {
        $ids = fn (string $query) => collect($this->getJson("/api/v1/admin/audit-logs?{$query}")->assertOk()->json('data'))->pluck('id')->all();

        expect($ids("tenant={$this->b['tenant']->id}"))->toBe([$this->old->id])
            ->and($ids('actor_type=platform_admin'))->toBe([$this->new->id])
            ->and($ids('action=plan.updated'))->toBe([$this->new->id])
            ->and($ids('action=category.'))->toBe([$this->old->id])
            ->and($ids("subject_id={$this->old->subject_id}"))->toBe([$this->old->id])
            ->and($ids('from='.now()->subDays(2)->toDateString()))->toBe([$this->new->id])
            ->and($ids('to='.now()->subDays(5)->toDateString()))->toBe([$this->old->id])
            ->and($ids('search=boss@platform'))->toBe([$this->new->id])
            ->and($ids('search=Shoes'))->toBe([$this->old->id]);
    });

    it('validates the filters', function () {
        $this->getJson('/api/v1/admin/audit-logs?tenant=nope&actor_type=alien&from=yesterday&per_page=500')->assertUnprocessable()
            ->assertJsonStructure(['meta' => ['errors' => ['tenant', 'actor_type', 'from', 'per_page']]]);
    });

    it('shows one entry and lists the kinds of action recorded', function () {
        $this->getJson("/api/v1/admin/audit-logs/{$this->new->id}")->assertOk()->assertJsonPath('data.action', 'plan.updated')->assertJsonPath('data.meta.ip', '203.0.113.7');
        $this->getJson('/api/v1/admin/audit-logs/0198a000-0000-7000-8000-000000000000')->assertNotFound();
        $this->getJson('/api/v1/admin/audit-logs/actions')->assertOk()->assertJsonPath('data', ['category.updated', 'plan.updated']);
    });

    it('exports the filtered trail as a spreadsheet that cannot run formulas', function () {
        AuditLog::factory()->create(['action' => 'user.updated', 'subject_label' => '=HYPERLINK("http://evil.test")', 'actor_label' => '+cmd|calc']);

        $response = $this->get('/api/v1/admin/audit-logs/export?action=user.updated')->assertOk();
        $csv = $response->streamedContent();

        expect($response->headers->get('Content-Type'))->toContain('text/csv')
            ->and($csv)->toContain('"When (UTC)",Action,Store')->toContain("'=HYPERLINK")->toContain("'+cmd|calc")
            ->and($csv)->not->toContain('plan.updated');
    });
});

describe('retention', function () {
    it('removes entries older than the retention period and keeps the rest', function () {
        config(['audit.retention_days' => 30]);
        $old = AuditLog::factory()->create(['created_at' => now()->subDays(31)]);
        $recent = AuditLog::factory()->create(['created_at' => now()->subDays(29)]);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(AuditLog::query()->whereKey($old->id)->exists())->toBeFalse()->and(AuditLog::query()->whereKey($recent->id)->exists())->toBeTrue();
    });

    it('keeps everything when retention is switched off', function () {
        config(['audit.retention_days' => 0]);
        $ancient = AuditLog::factory()->create(['created_at' => now()->subYears(9)]);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(AuditLog::query()->whereKey($ancient->id)->exists())->toBeTrue();
    });
});
