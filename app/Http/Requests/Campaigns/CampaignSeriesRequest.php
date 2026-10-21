<?php

namespace App\Http\Requests\Campaigns;

use App\Http\Requests\Concerns\ValidatesSafeUrls;
use App\Models\CampaignSeries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CampaignSeriesRequest extends FormRequest
{
    use ValidatesSafeUrls;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['button_label', 'button_url', 'ends_on'] as $key) {
            if ($this->has($key) && trim((string) $this->input($key)) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $required = $create ? 'required' : 'sometimes';

        return [
            'segment_id' => [$required, 'uuid'],
            'subject' => [$required, 'string', 'min:3', 'max:150'],
            'body' => [$required, 'string', 'min:10', 'max:5000'],
            'button_label' => ['nullable', 'string', 'max:40', 'required_with:button_url'],
            'button_url' => ['nullable', 'string', 'max:2048', 'required_with:button_label', $this->httpsUrl()],
            'frequency' => [$required, Rule::in([CampaignSeries::FREQUENCY_WEEKLY, CampaignSeries::FREQUENCY_MONTHLY])],
            // 1 = Monday … 7 = Sunday. The 28th is the latest day of the month, so every month has it.
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'],
            'send_time' => [$required, 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
            'status' => ['sometimes', Rule::in([CampaignSeries::STATUS_ACTIVE, CampaignSeries::STATUS_PAUSED])],
        ];
    }
}
