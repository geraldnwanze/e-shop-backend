<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ApiErrorCodeEnum;
use App\Enums\UserRole;
use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AdminAudit;
use App\Support\ApiResponse;
use App\Support\Like;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

#[Group('Platform admin: Users', weight: 41)]
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'tenant' => ['nullable', 'uuid'],
            'domain' => ['nullable', 'string', 'max:253'],
            'email_status' => ['nullable', Rule::in(['verified', 'unverified'])],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()->with('tenant.domains')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereLike('name', Like::contains($term))->orWhereLike('email', Like::contains($term))))
            ->when($filters['role'] ?? null, fn ($q, $v) => $q->where('role', $v))
            ->when($filters['tenant'] ?? null, fn ($q, $v) => $q->whereHas('tenant', fn ($t) => $t->where('id', $v)))
            ->when($this->domainTerm($filters['domain'] ?? null), fn ($q, $term) => $q->whereHas('tenant.domains', fn ($d) => $d->whereLike('domain', Like::contains($term))))
            ->when(($filters['email_status'] ?? null) === 'verified', fn ($q) => $q->whereNotNull('email_verified_at'))
            ->when(($filters['email_status'] ?? null) === 'unverified', fn ($q) => $q->whereNull('email_verified_at'))
            ->when(($filters['status'] ?? null) === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->whereNull('suspended_at'))
            ->latest('id')->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success(UserResource::collection($users));
    }

    /**
     * Lets an admin paste a full URL: "https://Shop.Example.com/" filters on "shop.example.com".
     */
    private function domainTerm(?string $input): ?string
    {
        $term = strtolower(trim((string) $input));
        $term = rtrim((string) preg_replace('#^https?://#', '', $term), '/');

        return $term === '' ? null : $term;
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success(UserResource::make($user->load('tenant')));
    }

    public function update(UpdateAdminUserRequest $request, User $user): JsonResponse
    {
        $this->assertManageable($request, $user);
        $role = UserRole::from($request->validated('role'));

        if ($user->role === UserRole::MerchantOwner && $role !== UserRole::MerchantOwner
            && User::query()->where('tenant_id', $user->tenant_id)->where('role', UserRole::MerchantOwner->value)->whereKeyNot($user->getKey())->doesntExist()) {
            throw BaseApiException::conflict('A store must keep at least one owner.');
        }

        $user->role = $role;
        $user->save();

        return ApiResponse::success(UserResource::make($user->load('tenant')), 'User updated.');
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->assertManageable($request, $user);

        $user->forceFill(['suspended_at' => now()])->save();
        $user->tokens()->delete(); // sign them out everywhere, immediately

        return ApiResponse::success(UserResource::make($user->load('tenant')), 'User suspended.');
    }

    /**
     * Escape hatch when a merchant cannot receive the verification email: the store can sell again.
     */
    public function verifyEmail(Request $request, User $user): JsonResponse
    {
        $this->assertManageable($request, $user);

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return ApiResponse::success(UserResource::make($user->load('tenant')), 'Email marked as verified.');
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->assertManageable($request, $user);

        $user->forceFill(['suspended_at' => null])->save();

        return ApiResponse::success(UserResource::make($user->load('tenant')), 'User activated.');
    }

    public function revokeTokens(Request $request, User $user): JsonResponse
    {
        $this->assertManageable($request, $user);

        $user->tokens()->delete();
        AdminAudit::record('user.revoke_tokens', ['user_id' => $user->id]);

        return ApiResponse::noContent('All sessions revoked.');
    }

    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $this->assertManageable($request, $user);

        Password::sendResetLink(['email' => $user->email]);
        AdminAudit::record('user.password_reset', ['user_id' => $user->id]);

        return ApiResponse::noContent('Password reset email sent.');
    }

    /**
     * Admins manage merchant accounts only: never themselves, never other platform admins,
     * never storefront-role accounts that have no store.
     */
    private function assertManageable(Request $request, User $user): void
    {
        if ($user->isPlatformAdmin() || $user->is($request->user()) || $user->tenant_id === null) {
            throw new BaseApiException(ApiErrorCodeEnum::Forbidden, 'This account cannot be managed here.');
        }
    }
}
