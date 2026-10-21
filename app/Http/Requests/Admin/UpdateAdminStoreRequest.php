<?php

namespace App\Http\Requests\Admin;

use App\Enums\StoreStatus;
use App\Enums\ThemeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminStoreRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(StoreStatus::class)],
            'theme_id' => ['sometimes', 'uuid', Rule::exists('themes', 'id')->where('status', ThemeStatus::Active->value)],
        ];
    }
}
