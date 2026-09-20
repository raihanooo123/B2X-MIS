<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => 'payment',
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_'.fake()->unique()->bothify('##########'),
            'status' => 'captured',
            'amount_minor' => fake()->numberBetween(1000, 500000),
            'currency' => 'GBP',
        ];
    }

    public function refundOf(Payment $original, int $amountMinor): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $original->company_id,
            'order_id' => $original->order_id,
            'type' => 'refund',
            'gateway' => $original->gateway,
            'refunded_payment_id' => $original->id,
            'amount_minor' => $amountMinor,
        ]);
    }

    public function bacs(): static
    {
        return $this->state(fn (array $attributes) => [
            'gateway' => 'bacs',
            'gateway_reference' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'pending']);
    }
}
