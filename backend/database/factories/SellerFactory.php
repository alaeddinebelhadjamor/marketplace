<?php

namespace Database\Factories;

use App\Enums\SellerStatus;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Seller> */
class SellerFactory extends Factory
{
    protected $model = Seller::class;

    public const PASSWORD = 'MotDePasse123!';

    public function definition(): array
    {
        return [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'shop_title' => 'Boutique '.fake()->unique()->company(),
            'company' => fake()->company(),
            'contact_number' => '20'.fake()->numerify('######'),
            'address' => fake()->streetAddress(),
            'zipcode' => fake()->numerify('####'),
            'governorate' => 'Tunis',
            'has_patent' => 0,
            'status' => SellerStatus::Validated->value,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => SellerStatus::Pending->value]);
    }

    public function refused(): static
    {
        return $this->state(['status' => SellerStatus::Refused->value]);
    }

    /** Hash au format écrit par bcryptjs dans la v1 ($2b$). */
    public function withLegacyHash(): static
    {
        return $this->state(fn () => [
            'password_hash' => '$2b$'.substr(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]), 4),
        ]);
    }
}
