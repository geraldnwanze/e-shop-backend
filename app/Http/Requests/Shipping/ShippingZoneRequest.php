<?php

namespace App\Http\Requests\Shipping;

use App\Support\Shipping\Locations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ShippingZoneRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
            'countries' => ['required', 'array', 'min:1', 'max:40'],
            'countries.*' => ['required', 'string', 'distinct', Rule::in([...Locations::countryCodes(), Locations::EVERYWHERE])],
            'states' => ['nullable', 'array', 'max:37'],
            'states.*' => ['required', 'string', 'distinct', Rule::in(Locations::NIGERIAN_STATES)],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'rates' => ['required', 'array', 'max:10'],
            'rates.*.id' => ['nullable', 'uuid'],
            'rates.*.name' => ['required', 'string', 'max:80'],
            'rates.*.description' => ['nullable', 'string', 'max:160'],
            'rates.*.price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'rates.*.free_over' => ['nullable', 'integer', 'min:1', 'max:1000000000000'],
            'rates.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $countries = (array) $this->input('countries', []);

            if (in_array(Locations::EVERYWHERE, $countries, true) && count($countries) > 1) {
                $validator->errors()->add('countries', 'Choose either specific countries or "everywhere else", not both.');
            }

            if (! empty($this->input('states')) && $countries !== ['NG']) {
                $validator->errors()->add('states', 'States can only be chosen for a zone that covers Nigeria alone.');
            }
        }];
    }
}
