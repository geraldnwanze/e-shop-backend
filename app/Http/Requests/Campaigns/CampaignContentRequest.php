<?php

namespace App\Http\Requests\Campaigns;

use App\Http\Requests\Concerns\ValidatesSafeUrls;
use Illuminate\Foundation\Http\FormRequest;

class CampaignContentRequest extends FormRequest
{
    use ValidatesSafeUrls;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['button_label', 'button_url'] as $key) {
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
        $optional = $this->routeIs('*email-campaigns.audience') || $this->routeIs('*email-campaigns.update');

        return [
            'segment_id' => [$this->routeIs('*email-campaigns.test') || $this->routeIs('*email-campaigns.update') ? 'sometimes' : 'required', 'uuid'],
            // An ISO 8601 date and time with its offset (the dashboard sends UTC), at least a few minutes from now.
            'send_at' => ['sometimes', 'nullable', 'date'],
            'subject' => [$optional ? 'sometimes' : 'required', 'string', 'min:3', 'max:150'],
            'body' => [$optional ? 'sometimes' : 'required', 'string', 'min:10', 'max:5000'],
            'button_label' => ['nullable', 'string', 'max:40', 'required_with:button_url'],
            'button_url' => ['nullable', 'string', 'max:2048', 'required_with:button_label', $this->httpsUrl()],
        ];
    }
}
