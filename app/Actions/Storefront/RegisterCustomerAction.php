<?php

namespace App\Actions\Storefront;

use App\Models\Customer;
use Illuminate\Validation\ValidationException;

class RegisterCustomerAction
{
    /**
     * A guest record (created by guest checkout) can be claimed; a registered
     * email cannot. Claiming never exposes earlier guest orders: order history is
     * limited to orders placed while signed in (orders.placed_authenticated).
     *
     * @param  array{email: string, first_name: string, last_name: string, phone?: ?string, password: string, accepts_marketing?: bool}  $data
     */
    public function execute(array $data): Customer
    {
        $customer = Customer::withTrashed()->where('email', $data['email'])->first();

        if ($customer?->hasAccount()) {
            throw ValidationException::withMessages(['email' => ['An account with this email already exists.']]);
        }

        $customer ??= new Customer;
        $customer->fill([
            'email' => $data['email'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?? null,
        ]);
        $customer->password = $data['password'];
        $customer->deleted_at = null;
        $customer->save();

        if ($data['accepts_marketing'] ?? false) {
            $customer->optInToMarketing();
        }

        return $customer;
    }
}
