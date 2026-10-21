<?php

namespace App\Http\Requests\Catalog;

use App\Enums\DiscountType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => Str::upper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [$required, 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('discounts', 'code')->where('tenant_id', $tenantId)->ignore($this->route('discount')?->getKey())],
            'type' => [$required, Rule::enum(DiscountType::class)],
            'value' => [$required, 'integer', 'min:1', 'max:100000000', Rule::when($this->input('type') === DiscountType::Percentage->value, 'max:100')],
            'min_subtotal' => ['sometimes', 'integer', 'min:0'],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
