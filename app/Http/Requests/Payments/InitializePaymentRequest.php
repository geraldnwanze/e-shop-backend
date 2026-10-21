<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Deliberately has no amount, currency or callback fields: those are server-side.
 */
class InitializePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'uuid'],
            'email' => ['nullable', 'email'],
            'provider' => ['nullable', Rule::enum(PaymentProviderType::class)],
        ];
    }
}
