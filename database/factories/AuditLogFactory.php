<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'actor_type' => 'merchant',
            'actor_id' => fake()->uuid(),
            'actor_label' => fake()->safeEmail(),
            'action' => 'product.updated',
            'subject_type' => 'product',
            'subject_id' => fake()->uuid(),
            'subject_label' => ucfirst(fake()->words(2, true)),
            'changes' => ['price' => [1000, 1200]],
            'meta' => ['ip' => '203.0.113.7', 'source' => 'http'],
        ];
    }
}
