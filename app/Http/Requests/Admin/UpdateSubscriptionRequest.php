<?php

namespace App\Http\Requests\Admin;

use App\Enums\SubscriptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(SubscriptionStatus::class)],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'current_period_ends_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
