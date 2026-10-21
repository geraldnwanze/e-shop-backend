<?php

namespace Database\Factories;

use App\Models\CampaignSeries;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignSeries>
 */
class CampaignSeriesFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'segment_name' => 'Buyers',
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'frequency' => CampaignSeries::FREQUENCY_WEEKLY,
            'day_of_week' => 1,
            'send_time' => '08:00',
            'status' => CampaignSeries::STATUS_ACTIVE,
            'next_run_at' => now()->addDay(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }
}
