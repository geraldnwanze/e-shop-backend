<?php

namespace Database\Factories;

use App\Models\EmailCampaign;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailCampaign>
 */
class EmailCampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'segment_name' => 'Repeat customers',
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'status' => EmailCampaign::STATUS_SENT,
            'recipients_count' => 0,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }
}
