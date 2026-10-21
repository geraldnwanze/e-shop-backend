<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendTestEmailRequest extends FormRequest
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
            'type' => ['required', Rule::in(['abandoned_cart_first', 'abandoned_cart_followup', 'review_request', 'review_digest'])],
            // Lets the preview reflect a percentage the merchant has typed but not saved yet.
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }
}
