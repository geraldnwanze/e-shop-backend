<?php

namespace App\Http\Requests\Customers;

use App\Models\CustomerSegment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CustomerSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An unset rule arrives as null or blank from forms; drop it so only real rules remain.
     */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('rules'))) {
            $this->merge(['rules' => array_filter($this->input('rules'), fn ($v) => $v !== null && $v !== '')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') && ! $this->routeIs('*customer-segments.preview') ? 'required' : 'sometimes';
        $days = ['integer', 'min:1', 'max:3650'];

        return [
            'name' => $this->routeIs('*customer-segments.preview') ? ['sometimes'] : [$required, 'string', 'min:2', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rules' => [$this->routeIs('*customer-segments.update') ? 'sometimes' : 'required', 'array:'.implode(',', CustomerSegment::RULE_KEYS), 'min:1'],
            'rules.min_orders' => ['integer', 'min:1', 'max:100000'],
            'rules.max_orders' => ['integer', 'min:0', 'max:100000'],
            'rules.min_spent' => ['integer', 'min:1', 'max:100000000000'],
            'rules.max_spent' => ['integer', 'min:0', 'max:100000000000'],
            'rules.ordered_within_days' => $days,
            'rules.inactive_for_days' => $days,
            'rules.joined_within_days' => $days,
            'rules.has_account' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $rules = (array) $this->input('rules', []);

            foreach ([['min_orders', 'max_orders'], ['min_spent', 'max_spent']] as [$min, $max]) {
                if (isset($rules[$min], $rules[$max]) && $rules[$max] < $rules[$min]) {
                    $validator->errors()->add("rules.{$max}", 'The maximum cannot be lower than the minimum.');
                }
            }

            if (isset($rules['ordered_within_days'], $rules['inactive_for_days'])) {
                $validator->errors()->add('rules.inactive_for_days', 'Choose either "ordered recently" or "has not ordered for a while", not both.');
            }
        }];
    }
}
