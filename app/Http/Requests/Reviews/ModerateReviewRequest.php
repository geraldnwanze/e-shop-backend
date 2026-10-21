<?php

namespace App\Http\Requests\Reviews;

use App\Models\ProductReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateReviewRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in([ProductReview::STATUS_PUBLISHED, ProductReview::STATUS_PENDING, ProductReview::STATUS_HIDDEN])],
            'reply' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
