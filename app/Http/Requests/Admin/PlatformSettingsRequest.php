<?php

namespace App\Http\Requests\Admin;

use App\Enums\ThemeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlatformSettingsRequest extends FormRequest
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
            'platform' => ['sometimes', 'array:name,support_email'],
            'platform.name' => ['required_with:platform', 'string', 'max:80'],
            'platform.support_email' => ['nullable', 'email:rfc', 'max:255'],
            'registration' => ['sometimes', 'array:enabled'],
            'registration.enabled' => ['required_with:registration', 'boolean'],
            'billing' => ['sometimes', 'array:trial_days'],
            'billing.trial_days' => ['required_with:billing', 'integer', 'min:0', 'max:90'],
            'storefront' => ['sometimes', 'array:default_theme'],
            'storefront.default_theme' => ['required_with:storefront', 'string', Rule::exists('themes', 'slug')->where('status', ThemeStatus::Active->value)],
        ];
    }
}
