<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'order_id' => '0000'.fake()->unique()->numerify('#####'),
            'sku' => 'SKU-'.fake()->bothify('??##'),
            'product_name' => fake()->words(3, true),
            'qty' => fake()->numberBetween(1, 5),
            'price' => fake()->randomFloat(2, 10, 500),
            'vendor_id' => Seller::factory(),
            'processed_at' => now(),
        ];
    }
}
