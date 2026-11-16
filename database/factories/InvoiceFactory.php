<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'number' => fake()->regexify('[A-Za-z0-9]{20}'),
            'status' => fake()->randomElement(["draft","issued","paid"]),
            'total' => fake()->randomFloat(2, 0, 999999.99),
            'notes' => fake()->text(),
            'meta' => '{}',
            'issued_at' => fake()->dateTime(),
        ];
    }
}
