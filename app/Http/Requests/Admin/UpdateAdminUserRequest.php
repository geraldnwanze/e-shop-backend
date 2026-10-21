<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only merchant roles can be assigned through the API; platform admins are created from the CLI.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['role' => ['required', Rule::in([UserRole::MerchantOwner->value, UserRole::MerchantAdmin->value, UserRole::MerchantStaff->value])]];
    }
}
