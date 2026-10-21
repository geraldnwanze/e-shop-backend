<?php

namespace App\Http\Requests\Admin;

use App\Enums\PlanInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route group requires the manage-platform gate
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80'],
            'slug' => $creating ? ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('plans', 'slug')] : ['prohibited'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price' => [$required, 'integer', 'min:0', 'max:100000000000'],
            'currency' => [$required, 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'interval' => [$required, Rule::enum(PlanInterval::class)],
            'limits' => [$required, 'array:products,custom_domains'],
            'limits.products' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'limits.custom_domains' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'features' => ['sometimes', 'nullable', 'array', 'max:20'],
            'features.*' => ['string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
