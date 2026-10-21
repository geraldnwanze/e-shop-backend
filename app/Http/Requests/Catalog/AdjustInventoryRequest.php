<?php

namespace App\Http\Requests\Catalog;

use App\Enums\InventoryReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustInventoryRequest extends FormRequest
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
            'variant_id' => ['nullable', 'uuid'],
            'delta' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', Rule::in([InventoryReason::ManualAdjustment->value, InventoryReason::Restock->value])],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
