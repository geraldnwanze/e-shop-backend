<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $slug = $this->input('store_slug') ?: Str::slug((string) $this->input('store_name'));

        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'store_slug' => Str::lower((string) $slug),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'store_name' => ['required', 'string', 'max:120'],
            'store_slug' => [
                'required', 'string', 'min:3', 'max:40',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn(config('tenancy.reserved_subdomains')),
                Rule::unique('tenants', 'slug'),
                Rule::unique('stores', 'slug'),
            ],
        ];
    }
}
