<?php

namespace Database\Factories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => fake()->randomFloat(2, 0, 999999.99),
            'reference' => fake()->word(),
            'paid' => fake()->boolean(),
        ];
    }
}
