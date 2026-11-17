<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => Size::Square,
            'quality' => Quality::High,
            'reference_images' => [],
            // Roughly a short high-quality square prompt; the max covers the pixelforgeGeneration() test usage.
            'estimated_charge_nanos' => 295_000_000,
            'max_charge_nanos' => 350_000_000,
        ];
    }

    public function queued(string $providerId = 'gen_test'): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Queued,
            'provider_id' => $providerId,
        ]);
    }
}
