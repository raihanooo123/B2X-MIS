<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $totalGrossMinor = fake()->numberBetween(1000, 500000);

        return [
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'company_id' => Company::factory(),
            'order_id' => Order::factory(),
            'total_gross_minor' => $totalGrossMinor,
            'payment_terms' => 'net30',
            'due_at' => now()->addDays(30),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'paid_minor' => $attributes['total_gross_minor'],
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'overdue',
            'due_at' => now()->subDays(10),
        ]);
    }

    /** 02 §21.2: a public customer's receipt — no company, terms or due date. */
    public function receipt(): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_number' => 'RCP-'.fake()->unique()->numerify('######'),
            'company_id' => null,
            'order_id' => Order::factory()->state(['company_id' => null]),
            'payment_terms' => null,
            'due_at' => null,
        ]);
    }

    public function forShipment(int $shipmentId): static
    {
        return $this->state(fn (array $attributes) => ['shipment_id' => $shipmentId]);
    }
}
