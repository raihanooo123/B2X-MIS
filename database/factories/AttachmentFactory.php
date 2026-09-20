<?php

namespace Database\Factories;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'attachable_type' => 'product',
            'attachable_id' => 1,
            'disk' => 's3',
            'path' => 'attachments/'.fake()->uuid().'.pdf',
            'original_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1000, 500000),
            'is_customer_visible' => false,
        ];
    }

    public function forRma(int $rmaId): static
    {
        return $this->state(fn (array $attributes) => [
            'attachable_type' => 'rma',
            'attachable_id' => $rmaId,
            'mime_type' => 'image/jpeg',
            'original_name' => fake()->word().'.jpg',
        ]);
    }

    public function customerVisible(): static
    {
        return $this->state(fn (array $attributes) => ['is_customer_visible' => true]);
    }
}
