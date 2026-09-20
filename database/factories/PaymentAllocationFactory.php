<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'invoice_id' => Invoice::factory(),
            'amount_minor' => fake()->numberBetween(1000, 100000),
        ];
    }

    /** A negative reallocation/refund allocation — reason_code is required (payment_allocations_reason_chk). */
    public function reversal(string $reasonCode): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_minor' => -abs($attributes['amount_minor']),
            'reason_code' => $reasonCode,
        ]);
    }

    public function withReference(string $reference): static
    {
        return $this->state(fn (array $attributes) => ['allocation_reference' => $reference]);
    }
}
