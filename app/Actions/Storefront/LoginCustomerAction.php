<?php

namespace App\Actions\Storefront;

use App\Exceptions\BaseApiException;
use App\Models\Customer;
use Illuminate\Support\Facades\Hash;

class LoginCustomerAction
{
    /**
     * Customers are tenant-scoped: the lookup only sees the current storefront's customers.
     *
     * @return array{customer: Customer, token: string}
     */
    public function execute(string $email, string $password): array
    {
        $customer = Customer::query()->where('email', $email)->first();

        $valid = Hash::check($password, $customer?->password ?? Hash::make('timing-equalizer'));

        if ($customer === null || ! $customer->hasAccount() || ! $valid) {
            throw BaseApiException::invalidCredentials();
        }

        $expiration = config('sanctum.expiration');

        return [
            'customer' => $customer,
            'token' => $customer->createToken('storefront', ['*'], $expiration ? now()->addMinutes((int) $expiration) : null)->plainTextToken,
        ];
    }
}
