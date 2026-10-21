<?php

namespace App\Http\Requests\Pos;

use App\Enums\PosPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.variant_id' => ['nullable', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'payment_method' => ['required', Rule::enum(PosPaymentMethod::class)],
            'amount_tendered' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'discount_amount' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'customer' => ['sometimes', 'array:name,email,phone'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
