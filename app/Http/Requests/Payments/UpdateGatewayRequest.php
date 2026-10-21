<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentProviderType;
use App\Models\PaymentGateway;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGatewayRequest extends FormRequest
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
        $provider = $this->route('provider');
        $exists = PaymentGateway::query()->where('provider', $provider instanceof PaymentProviderType ? $provider->value : $provider)->exists();
        $flutterwave = $provider === PaymentProviderType::Flutterwave;
        $keyPattern = $flutterwave ? '/^FLWSECK(_TEST)?-[A-Za-z0-9\-_]+$/' : '/^sk_(test|live)_[A-Za-z0-9]+$/';

        return [
            'secret_key' => [$exists ? 'sometimes' : 'required', 'string', 'max:255', 'regex:'.$keyPattern],
            'public_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'webhook_secret' => [$flutterwave && ! $exists ? 'required' : 'sometimes', 'nullable', 'string', 'min:8', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['secret_key.regex' => 'This does not look like a valid secret key for this provider.'];
    }
}
