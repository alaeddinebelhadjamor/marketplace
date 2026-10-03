<?php

namespace Database\Factories;

use App\Enums\ReclamationType;
use App\Models\Reclamation;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reclamation> */
class ReclamationFactory extends Factory
{
    protected $model = Reclamation::class;

    public function definition(): array
    {
        return [
            'vendeur_id' => Seller::factory(),
            'type' => ReclamationType::Open->value,
            'vendeur_viewed' => 1,
            'admin_viewed' => 0,
        ];
    }

    public function resolved(): static
    {
        return $this->state(['type' => ReclamationType::Resolved->value]);
    }

    public function notification(): static
    {
        return $this->state(['type' => ReclamationType::Notification->value, 'vendeur_viewed' => 0, 'admin_viewed' => 1]);
    }
}
