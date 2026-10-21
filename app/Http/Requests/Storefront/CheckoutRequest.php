<?php

namespace App\Http\Requests\Storefront;

use App\Models\Store;
use App\Services\Themes\StoreCustomizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $customer = $this->input('customer');

        if (is_array($customer) && isset($customer['email'])) {
            $customer['email'] = Str::lower(trim((string) $customer['email']));
            $this->merge(['customer' => $customer]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $store = Store::query()->first();
        $settings = $store ? app(StoreCustomizationService::class)->settings($store)->settings : config('themes.default_settings');
        $phone = ($settings['checkout']['require_phone'] ?? false) ? 'required' : 'nullable';

        return [
            'cart_id' => ['required', 'uuid'],
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email:rfc', 'max:255'],
            'customer.accepts_marketing' => ['sometimes', 'boolean'],
            'customer.first_name' => ['required', 'string', 'max:100'],
            'customer.last_name' => ['required', 'string', 'max:100'],
            'customer.phone' => [$phone, 'string', 'max:30'],
            'shipping_rate_id' => ['nullable', 'uuid'],
            'discount_code' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...$this->addressRules('shipping_address', required: true),
            ...$this->addressRules('billing_address', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressRules(string $key, bool $required): array
    {
        $r = $required ? 'required' : 'required_with:'.$key;

        return [
            $key => [$required ? 'required' : 'nullable', 'array'],
            "$key.first_name" => [$r, 'string', 'max:100'],
            "$key.last_name" => [$r, 'string', 'max:100'],
            "$key.line1" => [$r, 'string', 'max:255'],
            "$key.line2" => ['nullable', 'string', 'max:255'],
            "$key.city" => [$r, 'string', 'max:100'],
            "$key.state" => ['nullable', 'string', 'max:100'],
            "$key.postal_code" => ['nullable', 'string', 'max:20'],
            "$key.country" => [$r, 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            "$key.phone" => ['nullable', 'string', 'max:30'],
        ];
    }
}
