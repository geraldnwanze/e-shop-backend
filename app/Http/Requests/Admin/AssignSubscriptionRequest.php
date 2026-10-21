<?php

namespace App\Http\Requests\Admin;

use App\Enums\SubscriptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignSubscriptionRequest extends FormRequest
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
            'plan_id' => ['required', 'uuid', Rule::exists('plans', 'id')->where('is_active', true)],
            'status' => ['sometimes', Rule::in([SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value])],
            'trial_ends_at' => ['nullable', 'date', 'after:now'],
            'current_period_ends_at' => ['nullable', 'date'],
        ];
    }
}
