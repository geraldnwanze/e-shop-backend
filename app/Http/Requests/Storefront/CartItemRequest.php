<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

class CartItemRequest extends FormRequest
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
        if ($this->isMethod('POST')) {
            return [
                'product_id' => ['required', 'uuid'],
                'variant_id' => ['nullable', 'uuid'],
                'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            ];
        }

        return ['quantity' => ['required', 'integer', 'min:1', 'max:100']];
    }
}
