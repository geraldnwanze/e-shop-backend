<?php

namespace App\Actions\Auth;

use App\Actions\Tenants\ProvisionStoreAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

class RegisterMerchantAction
{
    public function __construct(private readonly ProvisionStoreAction $provisionStore, private readonly BillingService $billing) {}

    /**
     * @param  array{name: string, email: string, password: string, store_name: string, store_slug: string}  $data
     */
    public function execute(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $tenant = $this->provisionStore->execute($data['store_name'], $data['store_slug']);
            $this->billing->startTrial($tenant);

            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->tenant_id = $tenant->getKey();
            $user->role = UserRole::MerchantOwner;
            $user->save();

            return $user;
        });

        event(new Registered($user));

        return $user;
    }
}
