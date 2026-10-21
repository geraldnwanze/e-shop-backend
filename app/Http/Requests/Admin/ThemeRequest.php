<?php

namespace App\Http\Requests\Admin;

use App\Enums\ThemeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The slug is the key the storefront's theme registry uses, so it is fixed at creation.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'slug' => $creating ? ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('themes', 'slug')] : ['prohibited'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'preview_image' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:#^https://\S+$#i'],
            'status' => ['sometimes', Rule::enum(ThemeStatus::class)],
        ];
    }
}
